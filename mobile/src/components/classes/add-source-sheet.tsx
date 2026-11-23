import * as ImagePicker from 'expo-image-picker';
import { useState } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button } from '@/components/button';
import { Notice } from '@/components/notice';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { PHOTO_QUALITY, photoFile, TEXT_CONTENT_MAX, TEXT_TITLE_MAX, UPLOAD_MIME_TYPES, uploadMimeTypeOf } from '@/lib/notebook-sources';
import { pickFile, type PickedFile } from '@/lib/pick-file';

type AddSourceSheetProps = {
  visible: boolean;
  /** `upload` or `text` while that source is being sent. */
  busy: 'upload' | 'text' | null;
  /** The server's refusal (a 422 message as it came), shown in the sheet. */
  error: string | null;
  /** How many more sources the notebook takes, when it has a limit. */
  room: number | null;
  onClose: () => void;
  onUpload: (file: PickedFile) => void;
  onText: (title: string, content: string) => void;
};

/**
 * "+ Sumber": a photo (a whiteboard, a printed page), a file (PDF, DOCX,
 * PPTX, TXT, MD, PNG, JPG), or pasted text, as the web's add buttons. A
 * photo or a file is read by the configured AI model; the server says when
 * that model cannot read it, and its words are shown as they are.
 */
export function AddSourceSheet({ visible, busy, error, room, onClose, onUpload, onText }: AddSourceSheetProps) {
  const theme = useTheme();
  // A Modal is a window of its own, where SafeAreaView reads no insets: the app's are applied by hand.
  const insets = useSafeAreaInsets();
  const [mode, setMode] = useState<'choose' | 'text'>('choose');
  const [title, setTitle] = useState('');
  const [content, setContent] = useState('');
  const [pickError, setPickError] = useState<string | null>(null);
  const full = room !== null && room <= 0;

  const [shownVisible, setShownVisible] = useState(visible);
  if (visible !== shownVisible) {
    setShownVisible(visible);
    // Each opening starts afresh; while open, a failed save keeps what was pasted.
    if (visible) {
      setMode('choose');
      setPickError(null);
      setTitle('');
      setContent('');
    }
  }

  async function pickPhoto() {
    setPickError(null);

    try {
      const picked = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: PHOTO_QUALITY, allowsMultipleSelection: false });
      const asset = picked.canceled ? null : (picked.assets[0] ?? null);

      if (asset) {
        onUpload(photoFile(asset));
      }
    } catch {
      setPickError('Foto tidak bisa dibuka. Coba pilih foto lain.');
    }
  }

  async function pickDocument() {
    setPickError(null);

    try {
      const picked = await pickFile(UPLOAD_MIME_TYPES);

      if (picked) {
        onUpload(picked.mimeType === 'application/octet-stream' ? { ...picked, mimeType: uploadMimeTypeOf(picked.name) } : picked);
      }
    } catch {
      setPickError('Berkas tidak bisa dibuka. Coba pilih berkas lain.');
    }
  }

  const shownError = pickError ?? error;
  const canSaveText = title.trim().length > 0 && content.trim().length > 0 && busy === null;

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.root}>
        <Pressable style={styles.backdrop} accessibilityRole="button" accessibilityLabel="Tutup" onPress={onClose} />
        <View style={[styles.sheet, { backgroundColor: theme.panel, paddingBottom: Spacing.three + insets.bottom }]} accessibilityViewIsModal>
          <ThemedText type="subtitle">{mode === 'text' ? 'Tempel teks sebagai sumber' : 'Tambah sumber'}</ThemedText>
          {shownError ? <Notice tone="error" message={shownError} /> : null}
          {full ? <Notice tone="warning" message="Notebook ini sudah mencapai batas sumber. Hapus sumber lain dulu." /> : null}

          {mode === 'choose' ? (
            <>
              <ThemedText type="small" themeColor="textSecondary">
                Foto dan berkas dibaca oleh penyedia AI yang dipakai server. Teks yang ditempel disimpan apa adanya. Untuk menulis catatanmu sendiri, pakai Catatan.
              </ThemedText>
              <Button icon="upload" variant="secondary" label="Foto" loading={busy === 'upload'} disabled={busy !== null || full} onPress={() => void pickPhoto()} />
              <Button icon="document" variant="secondary" label="Berkas" disabled={busy !== null || full} onPress={() => void pickDocument()} />
              <ThemedText type="caption" themeColor="textTertiary">
                PDF, DOCX, PPTX, TXT, MD, PNG, atau JPG.
              </ThemedText>
              <Button icon="copy" variant="secondary" label="Tempel teks sebagai sumber" disabled={busy !== null || full} onPress={() => setMode('text')} />
            </>
          ) : (
            <>
              <TextField label="Judul" value={title} onChangeText={setTitle} maxLength={TEXT_TITLE_MAX} editable={busy === null} />
              <TextField
                label="Isi"
                value={content}
                onChangeText={setContent}
                placeholder="Tempel teks materi, catatan kuliah, atau ringkasan."
                multiline
                maxLength={TEXT_CONTENT_MAX}
                editable={busy === null}
                style={styles.content}
              />
              <Button
                label="Simpan sebagai sumber"
                loading={busy === 'text'}
                disabled={!canSaveText || full}
                onPress={() => onText(title.trim(), content)}
              />
              <Button variant="secondary" size="small" label="Kembali" disabled={busy !== null} onPress={() => setMode('choose')} />
            </>
          )}
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    justifyContent: 'flex-end',
  },
  backdrop: {
    ...StyleSheet.absoluteFill,
    backgroundColor: 'rgba(0, 0, 0, 0.4)',
  },
  sheet: {
    gap: Spacing.two + Spacing.half,
    paddingHorizontal: Spacing.three + Spacing.half,
    paddingTop: Spacing.three + Spacing.half,
    borderTopLeftRadius: Radius.section,
    borderTopRightRadius: Radius.section,
  },
  content: {
    minHeight: 160,
    maxHeight: 280,
    textAlignVertical: 'top',
  },
});
