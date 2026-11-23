import { useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';

import type { NotebookMessage, NotebookSource } from '@/api/types/classes';
import { Button } from '@/components/button';
import { CitationText } from '@/components/classes/citation-text';
import { Panel, Section } from '@/components/section';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { formatDateTime } from '@/lib/format';
import { NOTE_MAX_CHARS, noteSourceBlock, noteSourceOf, noteTitle } from '@/lib/notebook-notes';

type NotesSectionProps = {
  notes: NotebookMessage[];
  sources: NotebookSource[];
  /** Sources the notebook can still take (null: unknown). */
  room: number | null;
  notebookId?: string;
  /** What is running, as `note-new`, `note-save-<id>`, `note-source-<id>` or `note-delete-<id>`. */
  busy: string | null;
  onCreate: () => void;
  /** Resolves to whether the server kept the text. */
  onSave: (note: NotebookMessage, content: string) => Promise<boolean>;
  onShare: (note: NotebookMessage) => void;
  onMakeSource: (note: NotebookMessage) => void;
  onDelete: (note: NotebookMessage) => void;
};

/**
 * Catatan: the student's own notes (no AI, no consent), each with its
 * Markdown shown as text with citation chips, an editor that saves when it
 * loses focus or on Simpan, Bagikan, Jadikan sumber and Hapus.
 */
export function NotesSection({ notes, sources, room, notebookId, busy, onCreate, onSave, onShare, onMakeSource, onDelete }: NotesSectionProps) {
  return (
    <Section
      label="Catatan"
      accessory={
        <View style={styles.accessory}>
          <Button
            size="small"
            variant="secondary"
            label="+ Catatan baru"
            accessibilityLabel="Tulis catatan baru"
            loading={busy === 'note-new'}
            disabled={busy !== null}
            hitSlop={4}
            onPress={onCreate}
          />
        </View>
      }>
      {notes.length === 0 ? (
        <Panel>
          <ThemedText type="small" themeColor="textTertiary">
            Tulis catatanmu sendiri, atau salin jawaban ke sini. Catatan tidak dikirim ke AI.
          </ThemedText>
        </Panel>
      ) : null}
      {notes.map((note) => (
        <NoteCard
          key={note.id}
          note={note}
          source={noteSourceOf(note, sources)}
          sourceBlock={noteSourceBlock(note, sources, room)}
          notebookId={notebookId}
          busy={busy}
          onSave={onSave}
          onShare={onShare}
          onMakeSource={onMakeSource}
          onDelete={onDelete}
        />
      ))}
    </Section>
  );
}

type NoteCardProps = Pick<NotesSectionProps, 'notebookId' | 'busy' | 'onSave' | 'onShare' | 'onMakeSource' | 'onDelete'> & {
  note: NotebookMessage;
  source: NotebookSource | null;
  sourceBlock: string | null;
};

function NoteCard({ note, source, sourceBlock, notebookId, busy, onSave, onShare, onMakeSource, onDelete }: NoteCardProps) {
  const content = note.content ?? '';
  // A new, empty note opens in its editor.
  const [editing, setEditing] = useState(content === '');
  const [draft, setDraft] = useState(content);
  /** What the server has: shown as Tersimpan or Belum tersimpan. */
  const [saved, setSaved] = useState(content);
  const savedText = useRef(content);
  /** Leaving the field for Simpan blurs it first: the second save waits for the first and sends nothing new. */
  const pending = useRef<Promise<boolean> | null>(null);
  const changed = draft !== saved;
  const saving = busy === `note-save-${note.id}`;

  async function save(close: boolean) {
    const text = draft;

    if (pending.current) {
      await pending.current;
    }

    if (text !== savedText.current) {
      pending.current = onSave(note, text);

      const kept = await pending.current;

      pending.current = null;

      if (!kept) {
        return;
      }

      savedText.current = text;
      setSaved(text);
    }

    if (close) {
      setEditing(false);
    }
  }

  return (
    <Panel style={styles.card}>
      <View style={styles.head}>
        <ThemedText type="smallBold" style={styles.flex} numberOfLines={2}>
          {noteTitle(editing ? draft : content)}
        </ThemedText>
        <ThemedText type="caption" themeColor="textQuaternary">
          {formatDateTime(note.created_at)}
        </ThemedText>
      </View>
      {editing ? (
        <>
          <TextField
            label="Isi catatan"
            placeholder="Tulis dengan kata-katamu sendiri. Markdown didukung: # judul, - daftar, **tebal**."
            value={draft}
            onChangeText={setDraft}
            onBlur={() => void save(false)}
            multiline
            autoFocus={content === ''}
            maxLength={NOTE_MAX_CHARS}
            style={styles.editor}
          />
          <ThemedText type="caption" themeColor="textTertiary">
            {saving ? 'Menyimpan…' : changed ? 'Belum tersimpan' : 'Tersimpan'}
          </ThemedText>
          <View style={styles.actions}>
            <Button size="small" icon="check" label="Simpan" loading={saving} hitSlop={4} onPress={() => void save(true)} />
            {changed ? (
              <Button
                size="small"
                variant="secondary"
                label="Batal"
                hitSlop={4}
                disabled={saving}
                onPress={() => {
                  setDraft(saved);
                  setEditing(saved === '');
                }}
              />
            ) : null}
          </View>
        </>
      ) : (
        <>
          {content.trim() !== '' ? (
            <CitationText content={content} citations={note.citations} notebookId={notebookId} messageId={note.id} />
          ) : (
            <ThemedText type="small" themeColor="textTertiary">
              Catatan masih kosong.
            </ThemedText>
          )}
          {source ? (
            <ThemedText type="caption" themeColor="textTertiary">{`Sudah jadi sumber: ${source.title ?? 'Sumber'}`}</ThemedText>
          ) : null}
          <View style={styles.actions}>
            <Button size="small" variant="secondary" icon="document" label="Ubah" accessibilityLabel={`Ubah ${noteTitle(content)}`} hitSlop={4} onPress={() => setEditing(true)} />
            <Button
              size="small"
              variant="secondary"
              icon="arrowUpRight"
              label="Bagikan"
              disabled={content.trim() === ''}
              hitSlop={4}
              onPress={() => onShare(note)}
            />
            {source ? null : (
              <Button
                size="small"
                variant="secondary"
                icon="upload"
                label="Jadikan sumber"
                accessibilityHint={sourceBlock ?? 'Catatan ini menjadi sumber teks notebook.'}
                loading={busy === `note-source-${note.id}`}
                disabled={sourceBlock !== null || busy !== null}
                hitSlop={4}
                onPress={() => onMakeSource(note)}
              />
            )}
            <Button
              size="small"
              variant="danger"
              icon="trash"
              label="Hapus"
              accessibilityLabel={`Hapus ${noteTitle(content)}`}
              loading={busy === `note-delete-${note.id}`}
              disabled={busy !== null}
              hitSlop={4}
              onPress={() => onDelete(note)}
            />
          </View>
          {sourceBlock && !source && content.trim() !== '' ? (
            <ThemedText type="caption" themeColor="textTertiary">
              {sourceBlock}
            </ThemedText>
          ) : null}
        </>
      )}
    </Panel>
  );
}

const styles = StyleSheet.create({
  accessory: {
    paddingRight: Spacing.two,
  },
  card: {
    gap: Spacing.two,
  },
  head: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
  },
  flex: {
    flex: 1,
  },
  editor: {
    minHeight: 120,
    maxHeight: 320,
    textAlignVertical: 'top',
  },
  actions: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two,
  },
});
