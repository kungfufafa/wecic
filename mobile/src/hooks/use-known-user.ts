import { useSyncExternalStore } from 'react';

import type { UserInfo } from '@/api/types';

let knownUser: UserInfo | null = null;
const listeners = new Set<() => void>();

/**
 * The login answer does not always name the student, but the dashboard
 * does; whichever screen learns the name shares it with the others.
 */
export function setKnownUser(user: UserInfo | null | undefined): void {
  if (!user || (!user.name && !user.nim && !user.student_id)) {
    return;
  }

  knownUser = user;
  listeners.forEach((listener) => listener());
}

export function clearKnownUser(): void {
  knownUser = null;
  listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}

export function useKnownUser(): UserInfo | null {
  return useSyncExternalStore(subscribe, () => knownUser);
}
