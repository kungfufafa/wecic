import { useState } from 'react';
import type { Ref } from 'react';
import { Pressable, StyleSheet, TextInput, View, type TextInputInstance, type TextInputProps } from 'react-native';

import { Icon, type IconName } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useResolvedColorScheme, useTheme } from '@/hooks/use-theme';

type TextFieldProps = TextInputProps & {
  /** Shown above the field; a search field goes without one. */
  label?: string;
  icon?: IconName;
  error?: string | null;
  /** A password field: the eye shows and hides what was typed. */
  secureToggle?: boolean;
  /** An × that empties the field once it has text. */
  clearable?: boolean;
  ref?: Ref<TextInputInstance>;
};

export function TextField({
  ref,
  label,
  icon,
  error,
  secureToggle = false,
  clearable = false,
  secureTextEntry,
  style,
  onFocus,
  onBlur,
  value,
  onChangeText,
  placeholder,
  ...rest
}: TextFieldProps) {
  const theme = useTheme();
  const scheme = useResolvedColorScheme();
  const [focused, setFocused] = useState(false);
  const [revealed, setRevealed] = useState(false);
  const secure = secureToggle ? !revealed : secureTextEntry;
  const outline = error ? theme.danger : focused ? theme.textQuaternary : theme.hairline;

  return (
    <View style={styles.field}>
      {label ? (
        <ThemedText type="small" themeColor="textSecondary" style={styles.label}>
          {label}
        </ThemedText>
      ) : null}
      <View style={[styles.inputRow, { backgroundColor: theme.elevated, borderColor: outline }]}>
        {icon ? <Icon name={icon} size={20} color={focused ? theme.text : theme.textTertiary} /> : null}
        <TextInput
          ref={ref}
          accessibilityLabel={label ?? placeholder}
          placeholder={placeholder}
          placeholderTextColor={theme.textTertiary}
          selectionColor={theme.brand}
          keyboardAppearance={scheme}
          secureTextEntry={secure}
          value={value}
          onChangeText={onChangeText}
          onFocus={(event) => {
            setFocused(true);
            onFocus?.(event);
          }}
          onBlur={(event) => {
            setFocused(false);
            onBlur?.(event);
          }}
          style={[styles.input, { color: theme.text }, style]}
          {...rest}
        />
        {secureToggle ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={revealed ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'}
            hitSlop={8}
            onPress={() => setRevealed((current) => !current)}>
            <Icon name={revealed ? 'eyeOff' : 'eye'} size={20} color={theme.textTertiary} />
          </Pressable>
        ) : null}
        {clearable && value ? (
          <Pressable accessibilityRole="button" accessibilityLabel="Hapus pencarian" hitSlop={8} onPress={() => onChangeText?.('')}>
            <Icon name="close" size={16} color={theme.textTertiary} />
          </Pressable>
        ) : null}
      </View>
      {error ? (
        <ThemedText type="caption" themeColor="danger" style={styles.label}>
          {error}
        </ThemedText>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  field: {
    gap: Spacing.two,
  },
  label: {
    paddingHorizontal: Spacing.one,
  },
  inputRow: {
    minHeight: 54,
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two + Spacing.one,
    paddingHorizontal: Spacing.three + Spacing.one,
    borderWidth: 1,
    borderRadius: Radius.pill,
  },
  input: {
    flex: 1,
    minHeight: 52,
    fontSize: 16,
    paddingVertical: 0,
  },
});
