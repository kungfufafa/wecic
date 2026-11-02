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
      onRefresh={refresh}>
      {summary.error ? <Notice tone="error" message={summary.error} /> : null}
      {history.error && !summary.error ? <Notice tone="error" message={history.error} /> : null}

      <Section
        label="Tagihan"
        accessory={
          finance ? <StatusPill label={hasOutstanding ? 'Ada tagihan' : 'Tidak ada tagihan'} tone={hasOutstanding ? 'attention' : 'positive'} /> : null
        }>
        <Panel padded={false}>
          {isLoading ? <SkeletonRows count={1} /> : null}
          {finance ? (
            <>
              <View style={styles.hero}>
                <View style={[styles.heroIcon, { backgroundColor: hasOutstanding ? theme.accentTint : theme.successTint }]}>
                  <Icon name={hasOutstanding ? 'alert' : 'check'} size={22} color={hasOutstanding ? theme.accent : theme.success} />
                </View>
                <View style={styles.heroText}>
                  <ThemedText type="small" themeColor="textTertiary">
                    {hasOutstanding ? 'Total tagihan belum dibayar' : 'Semua tagihan sudah dibayar'}
                  </ThemedText>
                  <ThemedText type="heading">{outstandingLabel}</ThemedText>
                </View>
              </View>
              {bills.map((bill, index) => (
                <Fragment key={`${bill.no ?? 'bill'}-${index}`}>
                  <Divider inset={Spacing.three} />
                  <View style={styles.paymentRow}>
                    <View style={styles.paymentText}>
                      <ThemedText type="bodyMedium">{bill.name ?? 'Tagihan'}</ThemedText>
                      {bill.description ? (
                        <ThemedText type="small" themeColor="textTertiary">
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
          <Panel style={styles.stagePanel}>
            <StageGrid stages={currentYear.stages} />
            {nextStage?.stage ? (
              <View style={[styles.nextStage, { backgroundColor: theme.elevated }]}>
                <Icon name="calendarClock" size={18} color={theme.brand} />
                <ThemedText type="small" themeColor="textSecondary" style={styles.flex}>
                  Berikutnya: tahap {nextStage.stage} tahun ke-{nextStage.study_year ?? currentYear.study_year},{' '}
                  {stageStatusLook(nextStage.status).label.toLowerCase()}
                  {nextStage.deadline ? `, batas ${formatDate(nextStage.deadline)}` : ''}.
                </ThemedText>
              </View>
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
                <Divider inset={Spacing.three} />
                <View style={styles.paymentRow}>
                  <View style={styles.paymentText}>
                    <ThemedText type="bodyMedium">Tahun ke-{year.study_year}</ThemedText>
                    <ThemedText type="small" themeColor="textTertiary">
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
          <Panel>
            <StageGrid stages={year.stages} />
          </Panel>
        </Section>
      ))}

      <Section label="Riwayat pembayaran" accessory={rows.length > 0 ? <Chip label={`${rows.length} transaksi`} /> : null}>
        <Panel padded={false}>
          {history.isLoading ? <SkeletonRows count={2} /> : null}
          {!history.isLoading && rows.length === 0 && !history.error ? (
            <EmptyState icon="wallet" title="Belum ada pembayaran" description="Pembayaran yang tercatat di portal akan tampil di sini." />
          ) : null}
          {rows.map((payment, index) => (
            <Fragment key={`${payment.no ?? 'payment'}-${index}`}>
              {index > 0 ? <Divider inset={Spacing.three} /> : null}
              <PaymentRow payment={payment} />
            </Fragment>
          ))}
        </Panel>
      </Section>
    </Screen>
  );
}

function PaymentRow({ payment }: { payment: Payment }) {
  const theme = useTheme();

  return (
    <View style={styles.paymentRow}>
      <View style={[styles.rowIcon, { backgroundColor: theme.successTint }]}>
        <Icon name="wallet" size={16} color={theme.success} />
      </View>
      <View style={styles.paymentText}>
        <ThemedText type="bodyMedium" numberOfLines={2}>
          {payment.description ?? payment.category ?? 'Pembayaran'}
        </ThemedText>
        <ThemedText type="small" themeColor="textTertiary">
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
  hero: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    padding: Spacing.three,
  },
  heroIcon: {
    width: 44,
    height: 44,
    borderRadius: 22,
    alignItems: 'center',
    justifyContent: 'center',
  },
  heroText: {
    flex: 1,
    gap: Spacing.half,
  },
  stagePanel: {
    gap: Spacing.three - Spacing.one,
    padding: Spacing.two,
  },
  nextStage: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two + Spacing.half,
    padding: Spacing.three - Spacing.one,
    borderRadius: 16,
  },
  paymentRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    padding: Spacing.three,
  },
  rowIcon: {
    width: 36,
    height: 36,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
  },
  paymentText: {
    flex: 1,
    gap: Spacing.half,
  },
});
