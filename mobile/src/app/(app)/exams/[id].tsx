import * as WebBrowser from 'expo-web-browser';
import { useLocalSearchParams } from 'expo-router';
import { Fragment, useState } from 'react';
import { StyleSheet, View } from 'react-native';

import type { ExamDetailResponse } from '@/api/types/actions';
import { FileUpload } from '@/components/actions/file-upload';
import { EmptyState } from '@/components/empty-state';
import { InfoRows } from '@/components/info-rows';
import { ListRow, ListRowTextInset } from '@/components/list-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatusPill } from '@/components/status-pill';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { usePortalFile } from '@/hooks/use-portal-file';
import { fieldLabel, hrefOf, textOf } from '@/lib/exams';

/** One exam: what the portal says about it, the answers sent, and the upload. */
export default function ExamDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const exam = useApiResource<ExamDetailResponse>(`exam/${encodeURIComponent(String(id))}`);
  const file = usePortalFile();
  const [formValues, setFormValues] = useState<Record<string, string>>({});
  const detail = exam.data?.data.exam_detail;
  const uploaded = detail?.status === 'uploaded';
  const alerts = (detail?.alerts ?? []).map(textOf).filter((text): text is string => text !== null);
  const requirements = (detail?.requirements ?? []).map(textOf).filter((text): text is string => text !== null);
  const links = (detail?.related_links ?? []).filter((link) => hrefOf(link) !== null);
  const submissions = detail?.submissions ?? [];
  const editableFields = detail?.form?.editable_fields ?? [];
  const canUpload = detail?.form?.available === true || detail?.form?.available_via_direct_submit === true;

  function openLink(href: string, label: string) {
    if (/^https?:\/\//i.test(href) && !/\/files\//i.test(href)) {
      void WebBrowser.openBrowserAsync(href);
    } else {
      void file.open(href, label);
    }
  }

  return (
    <Screen
      back
      title={detail?.course?.name ?? detail?.title ?? 'Ujian'}
      subtitle={[detail?.exam_type, detail?.course?.code].filter(Boolean).join(' · ') || null}
      accessory={detail ? <StatusPill label={uploaded ? 'Jawaban terkirim' : 'Belum mengirim'} tone={uploaded ? 'positive' : 'attention'} /> : null}
      refreshing={exam.isRefreshing}
      dataSavedAt={exam.savedAt}
      onRefresh={exam.refresh}>
      {exam.error ? <Notice tone="error" message={exam.error} /> : null}
      {alerts.map((alert) => (
        <Notice key={alert} tone="warning" message={alert} />
      ))}

      {exam.isLoading ? (
        <Section label="Memuat ujian">
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {detail ? (
        <>
          <Section label="Ujian">
            <Panel padded={false}>
              <InfoRows
                rows={[
                  { label: 'Mata kuliah', value: detail.course?.name },
                  { label: 'Dosen', value: detail.course?.lecturer },
                  { label: 'Jadwal', value: detail.course?.schedule },
                  { label: 'Status', value: detail.course?.status },
                ]}
              />
              {detail.description ? (
                <>
                  <Divider inset={Spacing.three} />
                  <ThemedText type="small" themeColor="textSecondary" style={styles.text} selectable>
                    {detail.description}
                  </ThemedText>
                </>
              ) : null}
            </Panel>
          </Section>

          {requirements.length > 0 ? (
            <Section label="Ketentuan">
              <Panel style={styles.list}>
                {requirements.map((requirement, index) => (
                  <ThemedText key={requirement} type="small" themeColor="textSecondary">
                    {index + 1}. {requirement}
                  </ThemedText>
                ))}
              </Panel>
            </Section>
          ) : null}

          {links.length > 0 ? (
            <Section label="Soal dan tautan">
              <Panel padded={false}>
                {links.map((link, index) => {
                  const href = hrefOf(link) ?? '';
                  const label = textOf(link) ?? 'Buka tautan';

                  return (
                    <Fragment key={`${href}-${index}`}>
                      {index > 0 ? <Divider inset={ListRowTextInset} /> : null}
                      <ListRow icon="document" label={label} trailingIcon="download" onPress={() => openLink(href, label)} />
                    </Fragment>
                  );
                })}
              </Panel>
            </Section>
          ) : null}

          <Section label="Jawaban terkirim">
            <Panel padded={false}>
              {submissions.length === 0 ? (
                <EmptyState icon="upload" title="Belum ada jawaban" description="Jawaban yang kamu kirim ke portal tampil di sini." />
              ) : (
                submissions.map((submission, index) => {
                  const href = hrefOf(submission);
                  const label = textOf(submission) ?? `Jawaban ${index + 1}`;
                  const date = typeof submission.date === 'string' ? submission.date : typeof submission.uploaded_at === 'string' ? submission.uploaded_at : null;

                  return (
                    <Fragment key={`${label}-${index}`}>
                      {index > 0 ? <Divider inset={ListRowTextInset} /> : null}
                      <ListRow
                        icon="document"
                        label={label}
                        value={date}
                        trailingIcon="download"
                        onPress={href ? () => void file.open(href, label) : undefined}
                      />
                    </Fragment>
                  );
                })
              )}
            </Panel>
          </Section>

          <Section label={uploaded ? 'Kirim ulang jawaban' : 'Kirim jawaban'}>
            <Panel style={styles.list}>
              {canUpload ? (
                <>
                  {editableFields.map((name) => (
                    <TextField
                      key={name}
                      label={fieldLabel(name)}
                      value={formValues[name] ?? ''}
                      onChangeText={(text) => setFormValues((current) => ({ ...current, [name]: text }))}
                    />
                  ))}
                  <FileUpload
                    path="exam"
                    fields={{
                      id: String(detail.exam_id ?? id),
                      ...Object.fromEntries(
                        Object.entries(formValues)
                          .filter(([, value]) => value.trim() !== '')
                          .map(([name, value]) => [`form_values[${name}]`, value]),
                      ),
                    }}
                    mimeTypes={['application/pdf', 'application/zip', 'application/x-rar-compressed', 'application/vnd.rar']}
                    maxBytes={20 * 1024 * 1024}
                    hint="PDF, ZIP, atau RAR, maksimal 20 MB"
                    label="Kirim jawaban"
                    onUploaded={exam.reload}
                  />
                </>
              ) : (
                <View style={styles.list}>
                  <ThemedText type="small" themeColor="textSecondary">
                    Portal belum membuka upload jawaban untuk ujian ini.
                  </ThemedText>
                </View>
              )}
            </Panel>
          </Section>
        </>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  text: {
    padding: Spacing.three,
  },
  list: {
    gap: Spacing.three - Spacing.one,
  },
});
