import type { ComponentType } from 'preact';

import type { MountName } from './config';

/**
 * The registry the four mounts plug into (#107, #108).
 *
 * ## Why a registry rather than four imports
 *
 * #106 builds everything the mounts stand on and none of the mounts. A shell
 * that imported them directly would either not compile or ship four empty
 * components, and the next issue would have to unpick which. A registry lets
 * #107 add one line and lets this issue's tests assert the shell's behaviour
 * **when a mount is missing**, which is a real state: a widget built before a
 * mount exists, and an operator who typed `data-mount="calendar"` on a bundle
 * that has one.
 *
 * It is also what keeps the 80 KB budget honest. Whatever the mounts cost, they
 * cost it because they registered — nothing here pulls a mount into the bundle
 * that an operator's page did not ask for.
 */

export interface MountProps {
  readonly productUuid: string | null;
  readonly category: string | null;
}

export type MountComponent = ComponentType<MountProps>;

const registry = new Map<MountName, MountComponent>();

export function registerMount(name: MountName, component: MountComponent): void {
  registry.set(name, component);
}

export function resolveMount(name: MountName): MountComponent | null {
  return registry.get(name) ?? null;
}

/** Test seam: module state, and each test wants its own. */
export function clearMounts(): void {
  registry.clear();
}
