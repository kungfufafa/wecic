import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { ApiError } from '@/api/client';
import type { ActionResponse } from '@/api/types/actions';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { Icon } from '@/components/icon';
import { Notice } from '@/components/notice';
import { ProgressBar } from '@/components/progress-bar';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useOperation } from '@/hooks/use-operation';
import { useTheme } from '@/hooks/use-theme';
import { isConnectionFailure, isQueuedOffline, operationIdOf } from '@/lib/operations';
import { formatBytes, pickFile, type PickedFile } from '@/lib/pick-file';

type FileUploadProps = {
  /** `assignment` or `exam`. */
  path: string;
  /** The form fields besides the file. */
  fields: Record<string, string>;
  mimeTypes: string[];
  maxBytes: number;
  /** "PDF, maksimal 10 MB". */
  hint: string;
  label: string;
  onUploaded?: () => void;
};

/**
 * Choose a file, send it, and follow the portal upload to its end. An
 * upload of the same target still running (409) is followed instead.
 */
export function FileUpload({ path, fields, mimeTypes, maxBytes, hint, label, onUploaded }: FileUploadProps) {
  const theme = useTheme();
  const { api } = useSession();
  const [file, setFile] = useState<PickedFile | null>(null);
  const [error, setError] = useState<string | null>(null);
  const operation = useOperation((ok) => {
    if (ok) {
      setFile(null);
      onUploaded?.();
    }
  });
  const running = operation.state.phase === 'running';

  async function choose() {
    setError(null);
    operation.reset();
    const picked = await pickFile(mimeTypes);

    if (picked === null) {
      return;
    }

    if (picked.size !== null && picked.size > maxBytes) {
      setError(`File terlalu besar. ${hint}.`);

      return;
    }

    setFile(picked);
  }

  async function send() {
    if (file === null) {
      return;
    }

    setError(null);
    operation.start();
    const form = new FormData();

    Object.entries(fields).forEach(([key, value]) => form.append(key, value));
    if (file.webFile) {
      form.append('attachment', file.webFile, file.name);
    } else {
      // React Native reads a file part from its uri.
      form.append('attachment', { uri: file.uri, name: file.name, type: file.mimeType } as unknown as Blob);
    }

    try {
      const response = await api.post<ActionResponse>(path, form);

      if (isQueuedOffline(response)) {
        operation.finish(true, response.message);

        return;
      }

      const operationId = operationIdOf(response);

      if (operationId) {
        await operation.track(operationId);
      } else {
        operation.finish(true, response.message || 'File terkirim.');
      }
    } catch (caught) {
      const runningId = caught instanceof ApiError && caught.status === 409 ? operationIdOf(caught.body) : null;

      if (runningId) {
        await operation.track(runningId);

        return;
      }

      operation.reset();
      setError(
        isConnectionFailure(caught)
          ? 'Tidak ada koneksi. File belum terkirim, kirim lagi nanti.'
          : caught instanceof Error
            ? caught.message
            : String(caught),
      );
    }
  }

  const state = operation.state;

  return (
    <View style={styles.upload}>
      {file ? (
        <View style={[styles.file, { backgroundColor: theme.elevated }]}>
          <Icon name="document" size={20} color={theme.text} />
          <View style={styles.fileText}>
            <ThemedText type="smallBold" numberOfLines={1}>
              {file.name}
            </ThemedText>
            <ThemedText type="caption" themeColor="textTertiary">
              {formatBytes(file.size) || hint}
            </ThemedText>
          </View>
        </View>
      ) : (
        <ThemedText type="small" themeColor="textTertiary">
          {hint}
        </ThemedText>
      )}

      {state.phase === 'running' ? (
        <View style={styles.progress}>
          <ProgressBar percent={state.operation?.percent ?? 8} />
          <ThemedText type="caption" themeColor="textTertiary">
            {state.operation?.message ?? 'Mengirim file ke portal…'}
          </ThemedText>
        </View>
      ) : null}
      {state.phase === 'done' ? <Notice tone={state.ok ? 'success' : 'error'} message={state.message} /> : null}
      {error ? <Notice tone="error" message={error} /> : null}

      <View style={styles.buttons}>
        <Button variant="secondary" icon="upload" label={file ? 'Ganti file' : 'Pilih file'} disabled={running} onPress={choose} style={styles.flex} />
        {file ? <Button label={label} icon="send" loading={running} onPress={send} style={styles.flex} /> : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  upload: {
    gap: Spacing.two + Spacing.half,
  },
  file: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two + Spacing.one,
    paddingHorizontal: Spacing.three - Spacing.one,
    paddingVertical: Spacing.two + Spacing.half,
    borderRadius: Radius.medium,
  },
  fileText: {
    flex: 1,
    gap: Spacing.half,
  },
  progress: {
    gap: Spacing.two,
  },
  buttons: {
    flexDirection: 'row',
    gap: Spacing.two,
  },
  flex: {
    flex: 1,
  },
});
