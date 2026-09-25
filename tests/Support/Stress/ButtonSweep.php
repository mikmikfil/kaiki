<?php

declare(strict_types=1);

namespace Tests\Support\Stress;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Features\SupportTesting\Testable;

use function Livewire\invade;

use Livewire\Livewire;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Presses every button of every panel screen once, as one person.
 *
 * The GET sweep ({@see DirtyOperator}, `PanelSweepTest`) sees the first render
 * of a screen and nothing after it. Everything a person does next — opening a
 * modal, cancelling it, sorting a column, the widget polling a minute later —
 * is a Livewire request that re-hydrates the component, and Filament re-checks
 * `canView()` / `canAccess()` on every one of them. The 2026-09-23 bug lived
 * exactly there: answering the last row of «Χρειάζονται προσοχή» made the next
 * request a 403, shown as a black box over the dashboard.
 *
 * What it does to each component it can mount:
 *
 *   1. re-renders it twice (`$refresh`, what `wire:poll` sends);
 *   2. reads the rendered HTML for every button — `mountAction(…)`,
 *      `mountTableAction(…)`, `mountTableBulkAction(…)`, form-component and
 *      infolist actions, and every other `wire:click` whose arguments are plain
 *      literals — and presses each one: mount, then submit with the defaults,
 *      then cancel whatever is still open. Every press runs inside a savepoint
 *      that is rolled back, so one button's delete never hides the next one;
 *   3. for a widget or page whose `canView()` / `canAccess()` depends on data,
 *      empties the tenant's rows one table at a time; wherever the answer flips
 *      from yes to no, re-renders the open component, which must not 403.
 *
 * A 403 or 404 on the *first* render is a decision (crew opening a page they
 * may not), not a finding. Anything after a successful first render that is a
 * 4xx/5xx or an exception is.
 */
final class ButtonSweep
{
    /** Tables that say who someone is or how the account is configured, not what it is doing. */
    private const KEEP_TABLES = [
        'users', 'role_assignments', 'tenant_domains', 'brand_profiles', 'api_keys',
        'integration_credentials', 'series_counters', 'audit_logs',
    ];

    /** Livewire methods pressing which says nothing about the screen. */
    private const SKIP_METHODS = [
        '$dispatch', '$dispatchSelf', '$dispatchTo', '$parent', '$js', '$el',
        'logout', 'callMountedAction', 'callMountedTableAction', 'callMountedTableBulkAction',
        'callMountedFormComponentAction', 'callMountedInfolistAction',
        'unmountAction', 'unmountTableAction', 'unmountTableBulkAction',
        'unmountFormComponentAction', 'unmountInfolistAction',
    ];

    /** @var list<string> */
    public array $findings = [];

    /**
     * 403s behind a data-dependent `canView()` on a component nothing can
     * re-hydrate: wrong, but unreachable until the component gains a poll, a
     * button or a listener. Printed, not failed on.
     *
     * @var list<string>
     */
    public array $latentFindings = [];

    /** @var array<string, int> */
    public array $tally = ['components' => 0, 'hidden' => 0, 'refused' => 0, 'requests' => 0, 'presses' => 0, 'flips' => 0];

    /** @var list<string> */
    public array $notes = [];

    private bool $latent = false;

    public function __construct(
        private readonly User $user,
        private readonly string $role,
        private readonly Tenant $tenant,
        private readonly int $maxPresses = 40,
        private readonly bool $verbose = false,
    ) {}

    /**
     * Every page, resource page, relation manager and widget a panel registers.
     *
     * @return list<array{class: class-string, params: array<string, mixed>, label: string, kind: string}>
     */
    public static function discover(string $panelId, Tenant $tenant, int $recordsPerModel = 2): array
    {
        $panel = Filament::getPanel($panelId);
        $components = [];

        foreach ($panel->getPages() as $page) {
            $components[] = ['class' => $page, 'params' => [], 'label' => class_basename($page), 'kind' => 'page'];
        }

        if (($profile = $panel->getProfilePage()) !== null) {
            $components[] = ['class' => $profile, 'params' => [], 'label' => class_basename($profile), 'kind' => 'page'];
        }

        $widgets = $panel->getWidgets();

        /** @var class-string<resource> $resource */
        foreach ($panel->getResources() as $resource) {
            $records = self::recordsOf($resource::getModel(), $tenant, $recordsPerModel);
            $recordPages = [];

            foreach ($resource::getPages() as $registration) {
                $page = $registration->getPage();

                if (! in_array(InteractsWithRecord::class, class_uses_recursive($page), true)) {
                    $components[] = ['class' => $page, 'params' => [], 'label' => class_basename($page), 'kind' => 'page'];

                    continue;
                }

                $recordPages[] = $page;

                foreach ($records as $i => $record) {
                    $components[] = [
                        'class' => $page,
                        'params' => ['record' => $record->getRouteKey()],
                        'label' => class_basename($page) . '#' . $record->getKey() . self::shape($record),
                        'kind' => 'record',
                    ];
                }
            }

            // Relation managers — registered ones, and the ones a form embeds
            // with `Livewire::make()` (the trip page's prices, extras and
            // questions), found where Filament keeps them — on the same records.
            $host = $recordPages[0] ?? null;

            if ($host !== null) {
                foreach (self::relationManagers($resource) as $manager) {
                    foreach ($records as $owner) {
                        $components[] = [
                            'class' => $manager,
                            'params' => ['ownerRecord' => $owner, 'pageClass' => $host],
                            'label' => class_basename($manager) . '@' . class_basename($resource::getModel()) . '#' . $owner->getKey() . self::shape($owner),
                            'kind' => 'relation',
                        ];
                    }
                }
            }

            foreach ($resource::getWidgets() as $widget) {
                $widgets[] = is_string($widget) ? $widget : $widget->widget;
            }
        }

        foreach (array_unique($widgets) as $widget) {
            $components[] = ['class' => $widget, 'params' => [], 'label' => class_basename($widget), 'kind' => 'widget'];
        }

        return $components;
    }

    /** @param  array{class: class-string, params: array<string, mixed>, label: string, kind: string}  $component */
    public function sweep(array $component): void
    {
        $label = "[{$this->role}] {$component['label']}";
        $this->tally['components']++;

        if (! $this->wouldRender($component)) {
            return;
        }

        $t = $this->mount($component, $label, first: true);
        if ($t === null) {
            return;
        }

        if ($this->verbose) {
            fwrite(STDERR, "{$label}\n");
        }

        // What a poll sends, twice: the second request is the first one that
        // hydrates a snapshot the component itself dehydrated.
        foreach ([1, 2] as $_) {
            $t = $this->request($t, $component, $label, '$refresh', []);
            if ($t === null) {
                return;
            }
        }

        $pressed = 0;
        foreach ($this->buttons((string) $t->html(), $t->instance()) as [$method, $args]) {
            if ($pressed++ >= $this->maxPresses) {
                $this->notes[] = "{$label}: stopped after {$this->maxPresses} buttons";

                break;
            }

            $t = $this->press($t, $component, $label, $method, $args);
            if ($t === null) {
                return;
            }
        }

        if (in_array($component['kind'], ['widget', 'page'], true)) {
            $this->emptyUnderneath($component, $label, $this->talks($t));
        }
    }

    /**
     * The reverse: a component open while the account *gains* data.
     *
     * `FirstSteps` is the one that shows on an empty account and hides once
     * there is a booking; the next poll after the first booking lands must not
     * be a 403 either.
     *
     * @param  array{class: class-string, params: array<string, mixed>, label: string, kind: string}  $component
     */
    public function sweepWhileFilling(array $component, callable $fill): void
    {
        $label = "[{$this->role}] {$component['label']} (account filling)";
        $this->tally['components']++;

        if (! $this->wouldRender($component)) {
            return;
        }

        $t = $this->mount($component, $label, first: true);
        if ($t === null) {
            return;
        }

        $this->latent = ! $this->talks($t);
        $fill();
        $this->request($t, $component, "{$label} ▸ \$refresh after the first booking", '$refresh', []);
        $this->latent = false;
    }

    /**
     * Whether a dashboard would draw this at all.
     *
     * Filament asks a widget's `canView()` only on hydrate, never on mount, and
     * the dashboard simply leaves out a widget that says no. Mounting one that
     * says no and polling it would be a 403 no browser can reach.
     *
     * @param  array{class: class-string, params: array<string, mixed>, label: string, kind: string}  $component
     */
    private function wouldRender(array $component): bool
    {
        $class = $component['class'];

        $shows = match ($component['kind']) {
            'widget' => $class::canView(),
            // The gate a page applies before drawing a manager. One a form
            // embeds is gated by the form instead; this stands in for it.
            'relation' => $class::canViewForRecord($component['params']['ownerRecord'], $component['params']['pageClass']),
            default => true,
        };

        if ($shows) {
            return true;
        }

        $this->tally['hidden']++;

        return false;
    }

    /**
     * Whether an open component can ever send a request of its own: it polls,
     * it has a button, or it listens for an event. One that can do none of
     * those is re-hydrated by nothing, so a 403 on re-hydrate is latent.
     */
    private function talks(Testable $t): bool
    {
        $html = (string) $t->html();

        if (preg_match('/wire:(poll|init|model)/', $html) === 1 || $this->buttons($html, $t->instance()) !== []) {
            return true;
        }

        $instance = $t->instance();

        foreach ((new ReflectionClass($instance))->getMethods() as $method) {
            if ($method->getAttributes(On::class) !== []) {
                return true;
            }
        }

        return invade($instance)->getListeners() !== [];
    }

    private function report(string $line): void
    {
        if ($this->latent) {
            $this->latentFindings[] = $line . ' [latent: the component never sends a request of its own — no poll, no button, no listener]';

            return;
        }

        $this->findings[] = $line;
    }

    /** @param  array{class: class-string, params: array<string, mixed>, label: string, kind: string}  $component */
    private function mount(array $component, string $label, bool $first): ?Testable
    {
        $this->tally['requests']++;

        try {
            $t = Livewire::actingAs($this->user)->test($component['class'], $component['params']);
        } catch (Throwable $e) {
            if ($first && self::isDecision($e)) {
                $this->tally['refused']++;

                return null;
            }

            $this->report("{$label} — mount threw " . self::describe($e));

            return null;
        }

        $status = self::status($t);

        if ($status === 200) {
            return $t;
        }

        if ($first && in_array($status, [401, 403, 404], true)) {
            $this->tally['refused']++;

            return null;
        }

        $this->report("{$label} — mount answered {$status}");

        return null;
    }

    /**
     * One Livewire request against an open component.
     *
     * @param  array{class: class-string, params: array<string, mixed>, label: string, kind: string}  $component
     * @param  list<mixed>  $args
     * @return Testable|null the component to keep using, re-mounted if the request broke it
     */
    private function request(Testable $t, array $component, string $label, string $method, array $args): ?Testable
    {
        $this->tally['requests']++;
        $what = $method . '(' . self::argsForHumans($args) . ')';

        try {
            $t->call($method, ...$args);
        } catch (ValidationException) {
            return $t;
        } catch (Throwable $e) {
            $this->report("{$label} — {$what} threw " . self::describe($e));

            return $this->mount($component, $label, first: false);
        }

        $status = self::status($t);

        if ($status !== 200) {
            $this->report("{$label} — {$what} answered {$status}" . self::exceptionOf($t));

            return $this->mount($component, $label, first: false);
        }

        // A redirect or a download ends the screen in a browser; start again
        // from a fresh one rather than keep pressing a page nobody is on.
        $effects = invade($t)->lastState->getEffects();
        if (isset($effects['redirect']) || isset($effects['download'])) {
            return $this->mount($component, $label, first: false);
        }

        return $t;
    }

    /**
     * Mount a button's action, submit it with whatever the form defaults to,
     * then cancel whatever is still open — all inside a savepoint.
     *
     * @param  array{class: class-string, params: array<string, mixed>, label: string, kind: string}  $component
     * @param  list<mixed>  $args
     */
    private function press(Testable $t, array $component, string $label, string $method, array $args): ?Testable
    {
        $this->tally['presses']++;

        if ($this->verbose) {
            fwrite(STDERR, "    ▸ {$method}(" . self::argsForHumans($args) . ")\n");
        }

        DB::beginTransaction();

        try {
            $t = $this->request($t, $component, $label . ' ▸ ' . $method . '(' . self::argsForHumans($args) . ')', $method, $args);

            [$call, $unmount, $mounted] = match ($method) {
                'mountAction' => ['callMountedAction', 'unmountAction', 'mountedActions'],
                'mountTableAction' => ['callMountedTableAction', 'unmountTableAction', 'mountedTableActions'],
                'mountTableBulkAction' => ['callMountedTableBulkAction', 'unmountTableBulkAction', 'mountedTableBulkAction'],
                'mountFormComponentAction' => ['callMountedFormComponentAction', 'unmountFormComponentAction', 'mountedFormComponentActions'],
                'mountInfolistAction' => ['callMountedInfolistAction', 'unmountInfolistAction', 'mountedInfolistActions'],
                default => [null, null, null],
            };

            if ($t !== null && $mounted !== null && filled($t->get($mounted))) {
                $t = $this->request($t, $component, "{$label} ▸ {$method}(" . self::argsForHumans($args) . ') ▸ submit', $call, []);

                if ($t !== null && filled($t->get($mounted))) {
                    $t = $this->request($t, $component, "{$label} ▸ {$method}(" . self::argsForHumans($args) . ') ▸ cancel', $unmount, []);
                }
            }
        } finally {
            DB::rollBack();
        }

        return $t;
    }

    /**
     * Empty the account one table at a time under an open component, and
     * re-render it wherever that turns its `canView()` / `canAccess()` from
     * yes to no — the exact shape of the 2026-09-23 bug.
     *
     * @param  array{class: class-string, params: array<string, mixed>, label: string, kind: string}  $component
     */
    private function emptyUnderneath(array $component, string $label, bool $talks): void
    {
        $class = $component['class'];
        if (self::shows($class) !== true) {
            return;
        }

        $gate = static fn (): bool => self::shows($class) === true;

        foreach (self::operationalTables() as $table) {
            // First, cheaply: does emptying this table change the answer at all?
            if (! $this->underEmptied($table, static fn (): bool => ! $gate())) {
                continue;
            }

            // It does. Open the component on the full account, empty the
            // table under it, and send what the next poll would send.
            $this->tally['flips']++;
            $this->notes[] = "{$label}: shows or not depending on «{$table}»";
            $this->latent = ! $talks;

            DB::beginTransaction();

            try {
                $open = $this->mount($component, $label, first: false);

                if ($open !== null) {
                    $this->underEmptied($table, function () use ($open, $component, $label, $table): bool {
                        $this->request($open, $component, "{$label} ▸ open while «{$table}» emptied ▸ \$refresh", '$refresh', []);

                        return true;
                    });
                }
            } finally {
                DB::rollBack();
                $this->latent = false;
            }
        }
    }

    /**
     * The component's own answer to «may this be on screen now?», asked
     * again every time: it reads the database.
     *
     * @phpstan-impure
     */
    private static function shows(string $class): ?bool
    {
        return match (true) {
            method_exists($class, 'canView') => (bool) $class::canView(),
            method_exists($class, 'canAccess') => (bool) $class::canAccess(),
            default => null,
        };
    }

    /** Run $then with this tenant's rows of $table deleted, and put them back. */
    private function underEmptied(string $table, callable $then): bool
    {
        DB::beginTransaction();

        try {
            self::deferForeignKeys();
            DB::table($table)->where('tenant_id', $this->tenant->getKey())->delete();

            return (bool) $then();
        } catch (Throwable $e) {
            $this->notes[] = "emptying «{$table}» threw " . self::describe($e);

            return false;
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Every button the rendered HTML offers, as [method, arguments].
     *
     * @return list<array{0: string, 1: list<mixed>}>
     */
    private function buttons(string $html, object $instance): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $found = [];
        $perAction = [];

        // Action mounts, wherever they appear: wire:click, x-on:click="$wire.…", @click.
        preg_match_all('/\b(mountAction|mountTableAction|mountTableBulkAction|mountFormComponentAction|mountInfolistAction)\(/', $html, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[1] as [$method, $offset]) {
            $args = self::parseArgs($html, $offset + strlen($method) + 1);
            if ($args === null || $args === [] || ! is_string($args[0])) {
                continue;
            }

            $name = $method === 'mountFormComponentAction' ? ($args[1] ?? '') : $args[0];

            if ($method === 'mountTableBulkAction') {
                $args = [$args[0], $this->someTableKeys($html)];
            }

            // A row action is the same button on every row: two rows are enough.
            $key = $method . ':' . (is_string($name) ? $name : '');
            if (($perAction[$key] = ($perAction[$key] ?? 0) + 1) > 2) {
                continue;
            }

            $found[$method . json_encode($args)] = [$method, $args];
        }

        // Every other click, form submit and `$wire.method(…)` whose arguments
        // are literals, as long as the component really has that method.
        $calls = [];
        preg_match_all('/wire:(?:click|submit)(?:\.[\w.-]+)*="\s*(\$?[A-Za-z_]\w*)\s*(\()?/', $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $match) {
            $calls[] = [$match[1][0], isset($match[2]) && $match[2][1] >= 0 ? $match[2][1] + 1 : null];
        }
        preg_match_all('/\$wire\.(\$?[A-Za-z_]\w*)\(/', $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $match) {
            $calls[] = [$match[1][0], $match[0][1] + strlen($match[0][0])];
        }

        foreach ($calls as [$method, $argsAt]) {
            if (in_array($method, self::SKIP_METHODS, true)
                || str_starts_with($method, 'mount')
                // What a field's own JavaScript fetches — a select's option
                // labels, an upload's files — is not a button, and is called
                // only in the shape that field is in (single or multiple), so
                // calling every one of them blind reports the harness.
                || preg_match('/^(get|upload|removeUpload|removeFormUploadedFile|reorderFormUploadedFiles|_)/', $method) === 1
                || ! self::callable($instance, $method)) {
                continue;
            }

            $args = $argsAt === null ? [] : self::parseArgs($html, $argsAt);
            if ($args === null) {
                continue;
            }

            $found[$method . json_encode($args)] = [$method, $args];
        }

        return array_values($found);
    }

    /** A public Livewire action on this component, or one of the magic ones. */
    private static function callable(object $instance, string $method): bool
    {
        if (in_array($method, ['$refresh', '$set', '$toggle'], true)) {
            return true;
        }

        return method_exists($instance, $method) && (new ReflectionMethod($instance, $method))->isPublic();
    }

    /** @return list<string> */
    private function someTableKeys(string $html): array
    {
        preg_match_all("/mountTableAction\\('[^']+',\\s*'([^']+)'/", $html, $m);

        return array_values(array_slice(array_unique($m[1]), 0, 2));
    }

    /**
     * Parse a JS argument list of literals — strings, numbers, booleans, null,
     * `JSON.parse('…')` (what `Js::from()` writes) — starting after `(`.
     *
     * @return list<mixed>|null null when an argument is anything but a literal
     */
    private static function parseArgs(string $s, int $i): ?array
    {
        $args = [];
        $n = strlen($s);

        while ($i < $n) {
            while ($i < $n && ctype_space($s[$i])) {
                $i++;
            }

            if ($i >= $n) {
                return null;
            }

            if ($s[$i] === ')') {
                return $args;
            }

            if (str_starts_with(substr($s, $i, 11), 'JSON.parse(')) {
                $i += 11;
                $string = self::readString($s, $i);
                if ($string === null || ($s[$i] ?? '') !== ')') {
                    return null;
                }
                $i++;
                $args[] = json_decode($string, true);
            } elseif ($s[$i] === "'" || $s[$i] === '"') {
                $string = self::readString($s, $i);
                if ($string === null) {
                    return null;
                }
                $args[] = $string;
            } elseif (preg_match('/\G(-?\d+(?:\.\d+)?|true|false|null)/', $s, $lit, 0, $i) === 1) {
                $args[] = json_decode($lit[1], true);
                $i += strlen($lit[1]);
            } else {
                return null;
            }

            while ($i < $n && ctype_space($s[$i])) {
                $i++;
            }

            if (($s[$i] ?? '') === ',') {
                $i++;
            }
        }

        return null;
    }

    /** Read a quoted JS string at $i, unescaping it, and leave $i after the closing quote. */
    private static function readString(string $s, int &$i): ?string
    {
        $quote = $s[$i] ?? '';
        if ($quote !== "'" && $quote !== '"') {
            return null;
        }

        $out = '';
        $n = strlen($s);

        for ($i++; $i < $n; $i++) {
            $c = $s[$i];

            if ($c === '\\') {
                $next = $s[++$i] ?? '';
                if ($next === 'u') {
                    $out .= json_decode('"\\u' . substr($s, $i + 1, 4) . '"');
                    $i += 4;
                } else {
                    $out .= match ($next) {
                        'n' => "\n", 't' => "\t", default => $next,
                    };
                }

                continue;
            }

            if ($c === $quote) {
                $i++;

                return $out;
            }

            $out .= $c;
        }

        return null;
    }

    /** @return list<string> */
    private static function operationalTables(): array
    {
        static $tables = null;

        if ($tables === null) {
            $tables = [];
            foreach (Schema::getTableListing() as $table) {
                $table = (string) preg_replace('/^.*\./', '', $table);
                if (! in_array($table, self::KEEP_TABLES, true) && Schema::hasColumn($table, 'tenant_id')) {
                    $tables[] = $table;
                }
            }
        }

        return $tables;
    }

    private static function deferForeignKeys(): void
    {
        match (DB::getDriverName()) {
            'sqlite' => DB::statement('PRAGMA defer_foreign_keys = ON'),
            'mysql', 'mariadb' => DB::statement('SET FOREIGN_KEY_CHECKS = 0'),
            default => null,
        };
    }

    /**
     * Relation managers a resource registers, groups flattened.
     *
     * @param  class-string<resource>  $resource
     * @return list<class-string>
     */
    private static function relationManagers(string $resource): array
    {
        $managers = [];

        foreach ($resource::getRelations() as $relation) {
            foreach ($relation instanceof RelationGroup ? $relation->getManagers() : [$relation] as $manager) {
                $managers[] = $manager instanceof RelationManagerConfiguration ? $manager->relationManager : $manager;
            }
        }

        $file = (string) (new ReflectionClass($resource))->getFileName();
        foreach (glob(dirname($file) . '/' . class_basename($resource) . '/RelationManagers/*.php') ?: [] as $path) {
            $class = $resource . '\\RelationManagers\\' . basename($path, '.php');
            if (class_exists($class) && is_subclass_of($class, RelationManager::class)) {
                $managers[] = $class;
            }
        }

        return array_values(array_unique($managers));
    }

    /**
     * The first record, plus one from the bin and the last, for variety.
     *
     * @param  class-string<Model>  $model
     * @return list<Model>
     */
    private static function recordsOf(string $model, Tenant $tenant, int $limit): array
    {
        return Tenancy::forTenant($tenant, static function () use ($model, $limit): array {
            $soft = method_exists($model, 'bootSoftDeletes');
            $query = static fn () => $soft ? $model::query()->withoutGlobalScope(SoftDeletingScope::class) : $model::query();
            $key = (new $model)->getKeyName();

            $picked = array_filter([
                $query()->oldest($key)->first(),
                $soft ? $query()->whereNotNull('deleted_at')->first() : null,
                $query()->latest($key)->first(),
            ]);

            $unique = [];
            foreach ($picked as $record) {
                $unique[$record->getKey()] ??= $record;
            }

            return array_slice(array_values($unique), 0, $limit);
        });
    }

    private static function shape(Model $record): string
    {
        if (method_exists($record, 'trashed') && $record->trashed()) {
            return '(binned)';
        }

        $status = $record->getAttribute('status');

        return $status instanceof BackedEnum ? "({$status->value})" : '';
    }

    private static function status(Testable $t): int
    {
        return invade($t)->lastState->getResponse()->status();
    }

    private static function exceptionOf(Testable $t): string
    {
        $e = invade($t)->lastState->getResponse()->exception;

        return $e instanceof Throwable ? ' — ' . self::describe($e) : '';
    }

    private static function isDecision(Throwable $e): bool
    {
        return $e instanceof HttpExceptionInterface && in_array($e->getStatusCode(), [401, 403, 404], true)
            || $e instanceof AuthorizationException
            || $e instanceof ModelNotFoundException;
    }

    private static function describe(Throwable $e): string
    {
        return sprintf(
            '%s: %s @ %s:%d',
            class_basename($e),
            str($e->getMessage())->squish()->limit(200),
            str_replace(base_path() . DIRECTORY_SEPARATOR, '', $e->getFile()),
            $e->getLine(),
        );
    }

    /** @param  list<mixed>  $args */
    private static function argsForHumans(array $args): string
    {
        return implode(', ', array_map(static fn (mixed $a): string => (string) json_encode($a, JSON_UNESCAPED_UNICODE), $args));
    }
}
