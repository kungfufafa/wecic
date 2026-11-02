import { useLocalSearchParams } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { Fragment } from 'react';
import { Linking, StyleSheet, View } from 'react-native';

import type { AnnouncementResponse } from '@/api/types/admin';
import { Chip } from '@/components/chip';
import { ListRow, ListRowTextInset } from '@/components/list-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { usePortalFile } from '@/hooks/use-portal-file';
import { useTheme } from '@/hooks/use-theme';
import { formatDate } from '@/lib/format';

export default function AnnouncementScreen() {
  const theme = useTheme();
  const { id } = useLocalSearchParams<{ id: string }>();
  const file = usePortalFile();
  const resource = useApiResource<AnnouncementResponse>(typeof id === 'string' && id !== '' ? `announcement/${id}` : null);
  const announcement = resource.data?.data;
  const deadlines = announcement?.deadlines ?? [];
  const steps = announcement?.steps ?? [];
  const links = announcement?.links ?? [];
  const emails = announcement?.emails ?? [];

  return (
    <Screen
      back
      accessory={announcement?.category_label ? <Chip label={announcement.category_label} /> : null}
      refreshing={resource.isRefreshing}
      onRefresh={resource.refresh}>
      {resource.error ? <Notice tone="error" message={resource.error} /> : null}

      {resource.isLoading ? (
        <Section>
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {announcement ? (
        <>
          <View style={styles.header}>
            <ThemedText type="heading" accessibilityRole="header" selectable>
              {announcement.title}
            </ThemedText>
            <ThemedText type="small" themeColor="textTertiary">
              {[announcement.publisher, announcement.publisher_unit, announcement.published_label ?? formatDate(announcement.published_date)]
                .filter(Boolean)
                .join(' · ')}
            </ThemedText>
          </View>

          {deadlines.length > 0 ? (
            <Section label="Tanggal penting">
              <Panel padded={false}>
                {deadlines.map((deadline, index) => (
                  <Fragment key={`${deadline.date}-${index}`}>
                    {index > 0 ? <Divider inset={Spacing.three} /> : null}
                    <View style={styles.deadline}>
                      <View style={styles.deadlineTop}>
                        <ThemedText type="smallBold" style={styles.flex}>
                          {deadline.start && deadline.start !== deadline.date
                            ? `${formatDate(deadline.start)} – ${formatDate(deadline.date)}`
                            : formatDate(deadline.date)}
                        </ThemedText>
                        <StatusPill label={deadline.is_future ? 'Akan datang' : 'Sudah lewat'} tone={deadline.is_future ? 'attention' : 'muted'} />
                      </View>
                      {deadline.context ? (
                        <ThemedText type="small" themeColor="textTertiary">
                          {deadline.context}
                        </ThemedText>
                      ) : null}
                    </View>
                  </Fragment>
                ))}
              </Panel>
            </Section>
          ) : null}

          {steps.length > 0 ? (
            <Section label="Langkah-langkah">
              <Panel style={styles.steps}>
                {steps.map((step) => (
                  <View key={step.number} style={styles.step}>
                    <View style={[styles.stepNumber, { backgroundColor: theme.elevated }]}>
                      <ThemedText type="caption">{step.number}</ThemedText>
                    </View>
                    <ThemedText type="small" themeColor="textSecondary" style={styles.flex} selectable>
                      {step.text}
                    </ThemedText>
                  </View>
                ))}
              </Panel>
            </Section>
          ) : null}

          <Section label="Isi pengumuman">
            <Panel>
              <ThemedText type="default" themeColor="textSecondary" selectable style={styles.body}>
                {announcement.body_text?.trim() || announcement.excerpt || 'Pengumuman ini tidak berisi teks.'}
              </ThemedText>
            </Panel>
          </Section>

          {links.length > 0 || emails.length > 0 ? (
            <Section label="Tautan">
              <Panel padded={false}>
                {links.map((link, index) => (
                  <Fragment key={`${link.url}-${index}`}>
                    {index > 0 ? <Divider inset={ListRowTextInset} /> : null}
                    <ListRow
                      icon={link.is_file ? 'document' : 'link'}
                      label={link.label?.trim() || 'Buka tautan'}
                      value={link.url}
                      trailingIcon="arrowUpRight"
                      onPress={() => (link.is_campus_file ? void file.open(link.url, link.label) : void WebBrowser.openBrowserAsync(link.url))}
                    />
                  </Fragment>
                ))}
                {emails.map((email, index) => (
                  <Fragment key={email}>
                    {links.length > 0 || index > 0 ? <Divider inset={ListRowTextInset} /> : null}
                    <ListRow icon="mail" label={email} value="Kirim email" trailingIcon="arrowUpRight" onPress={() => void Linking.openURL(`mailto:${email}`)} />
                  </Fragment>
                ))}
              </Panel>
            </Section>
          ) : null}
        </>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
  },
  header: {
    gap: Spacing.two,
    paddingHorizontal: Spacing.one,
    paddingBottom: Spacing.one,
  },
  deadline: {
    gap: Spacing.one + Spacing.half,
    padding: Spacing.three,
  },
  deadlineTop: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
  },
  steps: {
    gap: Spacing.three - Spacing.one,
  },
  step: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.three - Spacing.one,
  },
  stepNumber: {
    width: 26,
    height: 26,
    borderRadius: Radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
  },
  body: {
    fontSize: 15,
    lineHeight: 23,
  },
});
