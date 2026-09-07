/**
 * The one HTTP client every mount on the page shares (WGT-8, WGT-16, WGT-17).
 *
 * ## Reads retry, writes never do
 *
 * WGT-16. A failed `GET /availability` is a request nobody minds repeating; a
 * failed `POST /bookings` may have **created the booking** — the response is
 * what got lost, not the write. Retrying that automatically is how a guest ends
 * up with two holds on the same boat and one of them expiring against a seat
 * somebody else wanted.
 *
 * `POST /price-quote` is a POST that reserves nothing, and it is still not
 * retried here: the classification is by **method**, because a rule that
 * depended on a per-endpoint list is a rule the next endpoint gets wrong. A
 * mount that wants a quote again asks again.
 *
 * ## Every request times out at ten seconds
 *
 * A widget on somebody else's page, on a phone in a harbour. Without a deadline
 * the failure is a spinner that never resolves — the "blank widget" WGT-16
 * exists to forbid — and `AbortController` turns it into an error state with a
 * retry button.
 *
 * ## Availability is cached for sixty seconds, and thrown away on a write
 *
 * WGT-17. A guest clicking back and forth across a calendar re-asks for the same
 * month constantly, and the answer only changes when somebody buys a seat. The
 * cache is keyed on the whole query string, lives in memory — no storage, WGT-12
 * — and is **cleared on any booking action**, because after a hold the numbers
 * this guest is looking at are the ones their own action just changed.
 */

export interface ApiError extends Error {
  readonly status: number | null;
  /** The contract's machine-readable code (`docs/api.md` §4), when there was one. */
  readonly code: string | null;
  readonly retryable: boolean;
}

interface RequestOptions {
  readonly method?: 'GET' | 'POST' | 'PUT';
  readonly query?: Record<string, string | number | undefined | null>;
  readonly body?: unknown;
  readonly cacheKey?: string;
  readonly signal?: AbortSignal;
}

/** WGT-16: ten seconds, whatever the request. */
const TIMEOUT_MS = 10_000;

/** WGT-16: two retries, so three attempts in the worst case. */
const MAX_RETRIES = 2;

/** WGT-17. */
const CACHE_TTL_MS = 60_000;

interface CacheEntry {
  readonly at: number;
  readonly value: unknown;
}

export class ApiClient {
  private readonly cache = new Map<string, CacheEntry>();

  constructor(
    private readonly baseUrl: string,
    private readonly publishableKey: string,
    private readonly fetchImpl: typeof fetch = globalThis.fetch.bind(globalThis),
    private readonly now: () => number = () => Date.now(),
    private readonly sleep: (ms: number) => Promise<void> = (ms) =>
      new Promise((resolve) => setTimeout(resolve, ms)),
  ) {}

  async get<T>(path: string, options: Omit<RequestOptions, 'method' | 'body'> = {}): Promise<T> {
    return this.request<T>(path, { ...options, method: 'GET' });
  }

  async post<T>(path: string, body: unknown, options: Omit<RequestOptions, 'method'> = {}): Promise<T> {
    return this.request<T>(path, { ...options, method: 'POST', body });
  }

  /**
   * WGT-17's invalidation. Called by every mount action that changes what a
   * seat count means — creating a draft, extending a hold, cancelling.
   */
  invalidate(): void {
    this.cache.clear();
  }

  private async request<T>(path: string, options: RequestOptions): Promise<T> {
    const method = options.method ?? 'GET';
    const url = this.url(path, options.query);
    const cacheKey = options.cacheKey ?? (method === 'GET' ? url : null);

    if (cacheKey !== null) {
      const hit = this.cache.get(cacheKey);

      if (hit !== undefined && this.now() - hit.at < CACHE_TTL_MS) {
        return hit.value as T;
      }
    }

    // Reads retry, writes get one attempt. See the class docblock for why the
    // line is drawn at the method rather than at a list of endpoints.
    const attempts = method === 'GET' ? MAX_RETRIES + 1 : 1;
    let lastError: ApiError | null = null;

    for (let attempt = 0; attempt < attempts; attempt++) {
      if (attempt > 0) {
        // Exponential, from 300 ms. Long enough that a server shedding load
        // gets a moment, short enough that a guest does not conclude the page
        // is broken.
        await this.sleep(300 * 2 ** (attempt - 1));
      }

      try {
        const value = await this.attempt<T>(url, method, options);

        if (cacheKey !== null) {
          this.cache.set(cacheKey, { at: this.now(), value });
        }

        return value;
      } catch (error) {
        lastError = error as ApiError;

        // A 4xx is the server saying the request is wrong, and asking twice
        // more does not make it right. Only 5xx, timeouts and network failures
        // are worth another attempt.
        if (!lastError.retryable) {
          throw lastError;
        }
      }
    }

    throw lastError ?? this.error('request_failed', null, true);
  }

  private async attempt<T>(url: string, method: string, options: RequestOptions): Promise<T> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);

    // An external `signal` (a mount unmounting mid-flight) aborts this one too,
    // so a widget removed from the DOM does not hold a request open.
    options.signal?.addEventListener('abort', () => controller.abort(), { once: true });

    try {
      const response = await this.fetchImpl(url, {
        method,
        signal: controller.signal,
        headers: {
          Authorization: `Bearer ${this.publishableKey}`,
          Accept: 'application/json',
          ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }),
        },
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
        // No cookies, ever (WGT-12, GDR-12). The key is the credential and a
        // cookie would make the widget a third-party tracker on somebody
        // else's domain.
        credentials: 'omit',
        mode: 'cors',
      });

      if (!response.ok) {
        throw await this.errorFromResponse(response);
      }

      return (await response.json()) as T;
    } catch (error) {
      if (isApiError(error)) {
        throw error;
      }

      // An abort is a timeout from the guest's point of view, and both are
      // worth retrying: the network, not the request, is what failed.
      throw this.error('network_error', null, true);
    } finally {
      clearTimeout(timer);
    }
  }

  private async errorFromResponse(response: Response): Promise<ApiError> {
    let code: string | null = null;

    try {
      const payload = (await response.json()) as { error?: { code?: string } };
      code = payload.error?.code ?? null;
    } catch {
      // A 502 from a proxy is HTML, and the status is the whole of what it
      // has to say.
    }

    return this.error(code ?? 'http_error', response.status, response.status >= 500);
  }

  private error(code: string, status: number | null, retryable: boolean): ApiError {
    const error = new Error(code) as ApiError & { status: number | null; code: string | null; retryable: boolean };

    error.status = status;
    error.code = code;
    error.retryable = retryable;

    return error;
  }

  private url(path: string, query: RequestOptions['query']): string {
    const search = new URLSearchParams();

    for (const [key, value] of Object.entries(query ?? {})) {
      if (value !== undefined && value !== null && value !== '') {
        search.set(key, String(value));
      }
    }

    const qs = search.toString();

    return `${this.baseUrl}${path}${qs === '' ? '' : `?${qs}`}`;
  }
}

function isApiError(error: unknown): error is ApiError {
  return error instanceof Error && 'retryable' in error;
}
