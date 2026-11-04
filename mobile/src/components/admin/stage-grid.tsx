import { StyleSheet, View } from 'react-native';

import type { PaymentStage } from '@/api/types/admin';
import { Icon } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { rupiah, stageStatusLook } from '@/lib/admin';

/** A year's four payment stages as tiles: paid in green, unpaid in gold, the rest quiet. */
export function StageGrid({ stages }: { stages: PaymentStage[] }) {
  const theme = useTheme();

  return (
    <View style={styles.grid}>
      {stages.map((stage) => {
        const look = stageStatusLook(stage.status);
        const color =
          stage.status === 'paid' ? theme.success : stage.status === 'unpaid' ? theme.accent : stage.status === 'upcoming' ? theme.brand : theme.textQuaternary;
        const amount = rupiah(stage.paid_amount ?? stage.bill_amount ?? stage.installment_amount);
        const detail = stage.paid_at_label ?? (stage.deadline ? `Batas ${stage.deadline}` : null);

        return (
          <View
            key={stage.stage}
            style={[styles.tile, { backgroundColor: theme.elevated }]}
            accessible
            accessibilityLabel={`Tahap ${stage.stage}: ${look.label}${amount ? `, ${amount}` : ''}${detail ? `, ${detail}` : ''}`}>
            <View style={styles.tileTop}>
              <ThemedText type="smallBold" style={styles.flex} numberOfLines={1}>
                Tahap {stage.stage}
              </ThemedText>
              <Icon name={stage.status === 'paid' ? 'check' : stage.status === 'unpaid' ? 'alert' : 'clock'} size={15} color={color} />
            </View>
            <ThemedText type="caption" style={{ color }} numberOfLines={1}>
              {look.label}
              {stage.is_partial ? ' · sebagian' : ''}
            </ThemedText>
            {amount ? (
              <ThemedText type="small" numberOfLines={1}>
                {amount}
              </ThemedText>
            ) : null}
            {detail ? (
              <ThemedText type="caption" themeColor="textTertiary" numberOfLines={1}>
                {detail}
              </ThemedText>
            ) : null}
          </View>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two,
  },
  tile: {
    flexBasis: '47%',
    flexGrow: 1,
    gap: Spacing.half,
    paddingVertical: Spacing.two + Spacing.half,
    paddingHorizontal: Spacing.three - Spacing.one,
    borderRadius: Radius.medium,
  },
  tileTop: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
  },
  flex: {
    flex: 1,
  },
});
