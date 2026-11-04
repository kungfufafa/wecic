import { ActivityIndicator, Pressable, StyleSheet, type PressableProps } from 'react-native';

import { Icon, type IconName } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useElevation, useTheme } from '@/hooks/use-theme';

type ButtonProps = Omit<PressableProps, 'children'> & {
  label: string;
  /** `primary` is the raised pill (charcoal in light mode, white in dark), one per screen; `secondary` sits on a panel. */
  variant?: 'primary' | 'secondary' | 'danger';
  size?: 'large' | 'small';
  icon?: IconName;
  loading?: boolean;
};

export function Button({ label, variant = 'primary', size = 'large', icon, loading = false, disabled, style, ...rest }: ButtonProps) {
  const theme = useTheme();
  const elevation = useElevation();
  const isDisabled = disabled || loading;
  const colors = {
    primary: { background: theme.primary, pressed: theme.primaryPressed, text: theme.onPrimary },
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
        variant === 'primary' && !isDisabled && { boxShadow: elevation.primary },
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
