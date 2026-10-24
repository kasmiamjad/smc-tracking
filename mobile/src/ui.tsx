import type { ReactNode } from 'react'
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View, type PressableProps, type TextInputProps, type ViewStyle } from 'react-native'

export const colors = {
  brand: '#1D4ED8',
  brandDark: '#1E3A8A',
  brandSoft: '#EFF6FF',
  text: '#0F172A',
  muted: '#64748B',
  border: '#CBD5E1',
  bg: '#F8FAFC',
  white: '#FFFFFF',
  green: '#059669',
  greenSoft: '#ECFDF5',
  amber: '#B45309',
  amberSoft: '#FFFBEB',
  red: '#DC2626',
  redSoft: '#FEF2F2',
}

type Variant = 'primary' | 'secondary' | 'danger' | 'success'

interface ButtonProps extends Omit<PressableProps, 'children' | 'style'> {
  title: string
  variant?: Variant
  loading?: boolean
  style?: ViewStyle
}

export function Button({ title, variant = 'primary', loading, disabled, style, ...rest }: ButtonProps) {
  const bg = { primary: colors.brand, secondary: colors.white, danger: colors.red, success: colors.green }[variant]
  const fg = variant === 'secondary' ? colors.text : colors.white
  return (
    <Pressable
      accessibilityRole="button"
      disabled={disabled || loading}
      style={({ pressed }) => [
        styles.button,
        { backgroundColor: bg, opacity: disabled ? 0.5 : pressed ? 0.85 : 1 },
        variant === 'secondary' && styles.buttonOutline,
        style,
      ]}
      {...rest}
    >
      {loading ? <ActivityIndicator color={fg} /> : <Text style={[styles.buttonText, { color: fg }]}>{title}</Text>}
    </Pressable>
  )
}

interface FieldProps extends TextInputProps {
  label: string
  error?: string
  hint?: string
  prefix?: string
}

export function Field({ label, error, hint, prefix, style, ...rest }: FieldProps) {
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <View style={[styles.inputRow, error ? { borderColor: colors.red } : null]}>
        {prefix ? <Text style={styles.prefix}>{prefix}</Text> : null}
        <TextInput placeholderTextColor="#94A3B8" style={[styles.input, style]} {...rest} />
      </View>
      {error ? <Text style={styles.error}>{error}</Text> : hint ? <Text style={styles.hint}>{hint}</Text> : null}
    </View>
  )
}

export function Notice({ tone = 'red', children }: { tone?: 'red' | 'amber' | 'green' | 'blue'; children: ReactNode }) {
  const map = {
    red: [colors.redSoft, colors.red],
    amber: [colors.amberSoft, colors.amber],
    green: [colors.greenSoft, colors.green],
    blue: [colors.brandSoft, colors.brandDark],
  } as const
  const [bg, fg] = map[tone]
  return (
    <View style={[styles.notice, { backgroundColor: bg }]}>
      <Text style={{ color: fg, fontSize: 14, lineHeight: 20 }}>{children}</Text>
    </View>
  )
}

export function Card({ children, style }: { children: ReactNode; style?: ViewStyle }) {
  return <View style={[styles.card, style]}>{children}</View>
}

const styles = StyleSheet.create({
  button: { minHeight: 52, borderRadius: 12, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 20 },
  buttonOutline: { borderWidth: 1, borderColor: colors.border },
  buttonText: { fontSize: 16, fontWeight: '600' },
  field: { marginBottom: 16 },
  label: { fontSize: 14, fontWeight: '600', color: colors.text, marginBottom: 6 },
  inputRow: { flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderColor: colors.border, borderRadius: 12, backgroundColor: colors.white },
  prefix: { paddingLeft: 14, fontSize: 17, color: colors.muted },
  input: { flex: 1, minHeight: 52, paddingHorizontal: 14, fontSize: 17, color: colors.text },
  error: { color: colors.red, fontSize: 13, marginTop: 4 },
  hint: { color: colors.muted, fontSize: 13, marginTop: 4 },
  notice: { borderRadius: 12, padding: 12, marginBottom: 16 },
  card: { backgroundColor: colors.white, borderRadius: 16, padding: 16, borderWidth: 1, borderColor: '#E2E8F0' },
})
