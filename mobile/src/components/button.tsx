import { ActivityIndicator, Pressable, StyleSheet, type PressableProps } from 'react-native';

import { Icon, type IconName } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type ButtonProps = Omit<PressableProps, 'children'> & {
  label: string;
  /** `primary` is the light pill, one per screen; `secondary` sits on charcoal. */
  variant?: 'primary' | 'secondary' | 'danger';
  size?: 'large' | 'small';
  icon?: IconName;
  loading?: boolean;
};

export function Button({ label, variant = 'primary', size = 'large', icon, loading = false, disabled, style, ...rest }: ButtonProps) {
  const theme = useTheme();
  const isDisabled = disabled || loading;
  const colors = {
    primary: { background: theme.light, pressed: theme.lightPressed, text: theme.onLight },
    secondary: { background: theme.elevated, pressed: theme.elevatedPressed, text: theme.text },
    danger: { background: theme.dangerTint, pressed: theme.elevated, text: theme.danger },
  }[variant];

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: isDisabled, busy: loading }}
      disabled={isDisabled}
      style={(state) => [
        styles.button,
        size === 'small' && styles.small,
        { backgroundColor: state.pressed ? colors.pressed : colors.background },
        isDisabled && styles.disabled,
        typeof style === 'function' ? style(state) : style,
      ]}
      {...rest}>
      {loading ? <ActivityIndicator color={colors.text} /> : icon ? <Icon name={icon} size={size === 'small' ? 16 : 20} color={colors.text} /> : null}
      <ThemedText type={size === 'small' ? 'smallBold' : 'bodyMedium'} style={{ color: colors.text }}>
        {label}
      </ThemedText>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  button: {
    minHeight: 56,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: Spacing.two + Spacing.half,
    paddingHorizontal: Spacing.four,
    borderRadius: Radius.large + 2,
  },
  small: {
    minHeight: 36,
    paddingHorizontal: Spacing.three - Spacing.one,
    borderRadius: Radius.pill,
    gap: Spacing.one + Spacing.half,
  },
  disabled: {
    opacity: 0.45,
  },
});
