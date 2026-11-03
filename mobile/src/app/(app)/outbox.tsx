import { Fragment, useState } from 'react';
import { Alert, Platform, StyleSheet, View } from 'react-native';

import type { OutboxItem, OutboxResponse } from '@/api/types/admin';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { EmptyState } from '@/components/empty-state';
import { Icon } from '@/components/icon';
import { MetaRow } from '@/components/meta-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { useTheme } from '@/hooks/use-theme';
import { formatDateTime } from '@/lib/format';

export default function OutboxScreen() {
  const { api } = useSession();
  const outbox = useApiResource<OutboxResponse>('outbox');
  const [cancelling, setCancelling] = useState<number | null>(null);
  const [message, setMessage] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);
  const data = outbox.data?.data;
  const items = data?.items ?? [];

  async function cancel(item: OutboxItem) {
    setCancelling(item.id);
    setMessage(null);

    try {
      const response = await api.delete(`outbox/${item.id}`);
      setMessage({ tone: 'success', text: response.message });
      await outbox.reload();
    } catch (error) {
      setMessage({ tone: 'error', text: error instanceof Error ? error.message : String(error) });
    } finally {
      setCancelling(null);
    }
  }

  function confirmCancel(item: OutboxItem) {
    const title = 'Batalkan kiriman ini?';
    const text = `${item.action_label ?? 'Kiriman'}${item.subject ? ` untuk ${item.subject}` : ''} tidak akan dikirim ke portal.`;

    if (Platform.OS === 'web') {
      if (window.confirm(`${title}\n${text}`)) {
        void cancel(item);
      }

      return;
    }

    Alert.alert(title, text, [
      { text: 'Tidak', style: 'cancel' },
      { text: 'Batalkan', style: 'destructive', onPress: () => void cancel(item) },
    ]);
  }

  return (
    <Screen
      back
      title="Antrean offline"
      subtitle="Absensi dan unggahan yang menunggu portal online"
      refreshing={outbox.isRefreshing}
      dataSavedAt={outbox.savedAt}
      onRefresh={outbox.refresh}>
      {outbox.error ? <Notice tone="error" message={outbox.error} /> : null}
      {message ? <Notice tone={message.tone} message={message.text} /> : null}
      {data?.offline ? <Notice tone="warning" message="Portal kampus sedang tidak bisa diakses. Antrean dikirim otomatis saat portal kembali." /> : null}
      {data && data.enabled === false ? <Notice message="Antrean offline tidak aktif di server WeCIC ini." /> : null}

      <Section label={items.length > 0 ? `${data?.queued_count ?? items.length} menunggu` : 'Antrean'}>
        <Panel padded={false}>
          {outbox.isLoading ? <SkeletonRows count={2} /> : null}
          {!outbox.isLoading && items.length === 0 && !outbox.error ? (
            <EmptyState
              icon="outbox"
              title="Tidak ada antrean"
              description="Saat portal tidak bisa diakses, absensi dan unggahanmu menunggu di sini lalu dikirim otomatis."
            />
          ) : null}
          {items.map((item, index) => (
            <Fragment key={item.id}>
              {index > 0 ? <Divider inset={Spacing.three} /> : null}
              <OutboxRow item={item} cancelling={cancelling === item.id} onCancel={() => confirmCancel(item)} />
            </Fragment>
          ))}
        </Panel>
      </Section>
    </Screen>
  );
}

function OutboxRow({ item, cancelling, onCancel }: { item: OutboxItem; cancelling: boolean; onCancel: () => void }) {
  const theme = useTheme();
  const failed = item.status === 'failed' || Boolean(item.last_error);
  const sent = item.status === 'sent';

  return (
    <View style={styles.row}>
      <View style={styles.rowTop}>
        <View style={[styles.icon, { backgroundColor: failed ? theme.dangerTint : theme.elevated }]}>
          <Icon name={item.file_name ? 'upload' : 'qr'} size={18} color={failed ? theme.danger : theme.text} />
        </View>
        <View style={styles.text}>
          <ThemedText type="bodyMedium">{item.action_label ?? 'Kiriman'}</ThemedText>
          {item.subject ? (
            <ThemedText type="small" themeColor="textTertiary">
              {item.subject}
            </ThemedText>
          ) : null}
        </View>
        {item.status_label ? <StatusPill label={item.status_label} tone={failed ? 'attention' : sent ? 'positive' : 'neutral'} /> : null}
      </View>
      <MetaRow icon="document" text={item.file_name} />
      <MetaRow
        icon="alarm"
        text={item.deadline_at ? `Batas ${formatDateTime(item.deadline_at)}${item.deadline_passed ? ' (sudah lewat)' : ''}` : null}
        color={item.deadline_passed ? theme.danger : undefined}
      />
      <MetaRow icon="clock" text={item.created_at ? `Diantrekan ${formatDateTime(item.created_at)}${item.attempts ? ` · ${item.attempts}× dicoba` : ''}` : null} />
      {item.last_error ? (
        <ThemedText type="small" themeColor="danger">
          {item.last_error}
        </ThemedText>
      ) : null}
      {sent ? null : (
        <View style={styles.actions}>
          <Button size="small" variant="danger" icon="cancel" label="Batalkan" loading={cancelling} onPress={onCancel} />
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    gap: Spacing.one + Spacing.half,
    padding: Spacing.three,
  },
  rowTop: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    marginBottom: Spacing.one,
  },
  icon: {
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
  },
  text: {
    flex: 1,
    gap: Spacing.half,
  },
  actions: {
    flexDirection: 'row',
    marginTop: Spacing.one,
  },
});
