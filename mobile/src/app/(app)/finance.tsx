import { Fragment } from 'react';
import { StyleSheet, View } from 'react-native';

import type { FinanceSummaryResponse, Payment, PaymentHistoryResponse } from '@/api/types/admin';
import { StageGrid } from '@/components/admin/stage-grid';
import { Chip } from '@/components/chip';
import { EmptyState } from '@/components/empty-state';
import { Icon } from '@/components/icon';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatRow } from '@/components/stat-row';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { useTheme } from '@/hooks/use-theme';
import { rupiah, stageStatusLook } from '@/lib/admin';
import { formatDate } from '@/lib/format';

export default function FinanceScreen() {
  const theme = useTheme();
  const summary = useApiResource<FinanceSummaryResponse>('finance/summary');
  const history = useApiResource<PaymentHistoryResponse>('finance/payments');
  const finance = summary.data?.data;
  const payments = history.data?.data;
  const hasOutstanding = finance?.has_outstanding === true || finance?.outstanding_summary?.has_outstanding === true;
  const outstandingLabel = finance?.outstanding_total_label ?? finance?.outstanding_summary?.total_amount_label ?? 'Rp 0';
  const bills = finance?.outstanding_bills ?? [];
  const currentYear = finance?.current_year ?? null;
  const nextStage = finance?.next_stage ?? null;
  const deadline = finance?.active_deadline ?? null;
  const totalPaid = rupiah(finance?.total_paid_amount ?? payments?.summary?.total_paid_amount) ?? finance?.portal_total_amount_label ?? '–';
  const pastYears = (payments?.payment_stages ?? []).filter((year) => !year.is_current);
  const byYear = payments?.summary?.by_study_year ?? [];
  const rows = payments?.payment_history ?? [];
  const isLoading = summary.isLoading || history.isLoading;

  function refresh() {
    void summary.refresh();
    void history.refresh();
  }

  return (
    <Screen
      back
      title="Keuangan"
      subtitle={finance?.current_academic_year ? `Tahun akademik ${finance.current_academic_year}` : null}
      refreshing={summary.isRefreshing || history.isRefreshing}
      onRefresh={refresh}
      dataSavedAt={summary.savedAt}>
      {summary.error ? <Notice tone="error" message={summary.error} /> : null}
      {history.error && !summary.error ? <Notice tone="error" message={history.error} /> : null}

      <Section
        label="Tagihan"
        accessory={
          finance ? <StatusPill label={hasOutstanding ? 'Ada tagihan' : 'Lunas'} tone={hasOutstanding ? 'attention' : 'positive'} /> : null
        }>
        <Panel padded={false}>
          {isLoading ? <SkeletonRows count={1} /> : null}
          {finance ? (
            <>
              <View style={styles.row} accessible accessibilityLabel={`${hasOutstanding ? 'Belum dibayar' : 'Semua tagihan sudah dibayar'}: ${outstandingLabel}`}>
                <Icon name={hasOutstanding ? 'alert' : 'check'} size={22} color={hasOutstanding ? theme.accent : theme.success} />
                <ThemedText type="bodyMedium" style={styles.flex} numberOfLines={1}>
                  {hasOutstanding ? 'Belum dibayar' : 'Semua sudah dibayar'}
                </ThemedText>
                <ThemedText type="subtitle" numberOfLines={1}>
                  {outstandingLabel}
                </ThemedText>
              </View>
              {bills.map((bill, index) => (
                <Fragment key={`${bill.no ?? 'bill'}-${index}`}>
                  <Divider />
                  <View style={styles.row}>
                    <View style={styles.rowText}>
                      <ThemedText type="bodyMedium" numberOfLines={1}>
                        {bill.name ?? 'Tagihan'}
                      </ThemedText>
                      {bill.description ? (
                        <ThemedText type="small" themeColor="textTertiary" numberOfLines={2}>
                          {bill.description}
                        </ThemedText>
                      ) : null}
                    </View>
                    <ThemedText type="smallBold" style={{ color: theme.accent }}>
                      {bill.amount_label ?? rupiah(bill.amount) ?? '–'}
                    </ThemedText>
                  </View>
                </Fragment>
              ))}
            </>
          ) : null}
        </Panel>
      </Section>

      {deadline ? <Notice tone="warning" message={`${deadline.text} (${deadline.days_left} hari lagi)`} /> : null}

      {currentYear ? (
        <Section
          label={`Tahun ke-${currentYear.study_year}${currentYear.academic_year ? ` · ${currentYear.academic_year}` : ''}`}
          accessory={<Chip label={`${currentYear.paid_count ?? 0}/${currentYear.stages.length} lunas`} />}>
          <Panel padded={false}>
            <View style={styles.stages}>
              <StageGrid stages={currentYear.stages} />
            </View>
            {nextStage?.stage ? (
              <>
                <Divider />
                <View style={styles.row}>
                  <Icon name="calendarClock" size={20} color={theme.brand} />
                  <ThemedText type="small" themeColor="textSecondary" style={styles.flex} numberOfLines={2}>
                    {[
                      `Berikutnya: tahap ${nextStage.stage}${
                        nextStage.study_year && nextStage.study_year !== currentYear.study_year ? ` tahun ke-${nextStage.study_year}` : ''
                      }`,
                      stageStatusLook(nextStage.status).label.toLowerCase(),
                      nextStage.deadline ? `batas ${formatDate(nextStage.deadline)}` : null,
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </ThemedText>
                </View>
              </>
            ) : null}
          </Panel>
        </Section>
      ) : null}

      {finance || payments ? (
        <Section label="Ringkasan">
          <Panel padded={false}>
            <StatRow
              stats={[
                { label: 'Total dibayar', value: totalPaid },
                { label: 'Transaksi', value: String(payments?.summary?.total_records ?? rows.length) },
              ]}
            />
            {byYear.map((year) => (
              <Fragment key={year.study_year}>
                <Divider />
                <View style={styles.row}>
                  <View style={styles.rowText}>
                    <ThemedText type="bodyMedium" numberOfLines={1}>
                      Tahun ke-{year.study_year}
                    </ThemedText>
                    <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
                      {[year.academic_year, `${year.payment_count ?? 0} pembayaran`, year.stages_paid?.length ? `tahap ${year.stages_paid.join(', ')}` : null]
                        .filter(Boolean)
                        .join(' · ')}
                    </ThemedText>
                  </View>
                  <ThemedText type="smallBold">{year.total_amount_label ?? rupiah(year.total_amount) ?? '–'}</ThemedText>
                </View>
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      {pastYears.map((year) => (
        <Section key={year.study_year} label={`Tahun ke-${year.study_year}${year.academic_year ? ` · ${year.academic_year}` : ''}`}>
          <Panel padded={false} style={styles.stages}>
            <StageGrid stages={year.stages} />
          </Panel>
        </Section>
      ))}

      <Section label="Riwayat pembayaran" accessory={rows.length > 0 ? <Chip label={`${rows.length} transaksi`} /> : null}>
        <Panel padded={false}>
          {history.isLoading ? <SkeletonRows count={2} /> : null}
          {!history.isLoading && rows.length === 0 && !history.error ? <EmptyState icon="wallet" title="Belum ada pembayaran" /> : null}
          {rows.map((payment, index) => (
            <Fragment key={`${payment.no ?? 'payment'}-${index}`}>
              {index > 0 ? <Divider /> : null}
              <PaymentRow payment={payment} />
            </Fragment>
          ))}
        </Panel>
      </Section>
    </Screen>
  );
}

function PaymentRow({ payment }: { payment: Payment }) {
  return (
    <View style={styles.row}>
      <View style={styles.rowText}>
        <ThemedText type="bodyMedium" numberOfLines={1}>
          {payment.description ?? payment.category ?? 'Pembayaran'}
        </ThemedText>
        <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
          {[payment.paid_at_label ?? formatDate(payment.paid_at), payment.category].filter(Boolean).join(' · ')}
        </ThemedText>
      </View>
      <ThemedText type="smallBold">{payment.amount_label ?? rupiah(payment.amount) ?? '–'}</ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    paddingVertical: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three,
  },
  rowText: {
    flex: 1,
    gap: Spacing.half,
  },
  stages: {
    padding: Spacing.two,
  },
});
