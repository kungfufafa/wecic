import { useCallback, useEffect, useState } from 'react';

import { useSession } from '@/auth/session-provider';
import { readOwnerPreference, writeOwnerPreference } from '@/lib/device-cache';
import { excludedFor, parseSourceSelection, withExcluded, type SourceSelectionStore } from '@/lib/notebook-sources';

const PREFERENCE = 'notebook-source-selection';

/** This app run's copy of each student's store, so leaving and reopening a notebook needs no read. */
const memory = new Map<string, SourceSelectionStore>();

async function storeOf(owner: string): Promise<SourceSelectionStore> {
  const kept = memory.get(owner);

  if (kept) {
    return kept;
  }

  const store = parseSourceSelection(await readOwnerPreference(owner, PREFERENCE));
  memory.set(owner, memory.get(owner) ?? store);

  return memory.get(owner) ?? store;
}

/**
 * The ready sources the student left out of one notebook's AI context,
 * kept per notebook in their own folder on the device (the web keeps it
 * for the page visit only). The web preview keeps it in memory.
 */
export function useSourceSelection(notebookId: string | undefined) {
  const { state: session } = useSession();
  const owner = session.status === 'signedIn' ? session.owner ?? '' : '';
  const [excluded, setExcludedState] = useState<Set<number>>(() => (notebookId ? excludedFor(memory.get(owner) ?? {}, notebookId) : new Set()));

  useEffect(() => {
    let active = true;

    if (!notebookId) {
      return;
    }

    void storeOf(owner).then((store) => {
      if (active) {
        setExcludedState(excludedFor(store, notebookId));
      }
    });

    return () => {
      active = false;
    };
  }, [owner, notebookId]);

  const setExcluded = useCallback(
    (next: Set<number>) => {
      setExcludedState(next);

      if (!notebookId) {
        return;
      }

      // Merged into the whole kept store, read first, so other notebooks' choices stay.
      void storeOf(owner).then((store) => {
        const updated = withExcluded(store, notebookId, next);

        memory.set(owner, updated);

        return writeOwnerPreference(owner, PREFERENCE, updated);
      });
    },
    [owner, notebookId],
  );

  return [excluded, setExcluded] as const;
}
