import { useEffect, useState } from 'react'
import { KeyboardAvoidingView, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native'
import { DEFAULT_COUNTRY_CODE } from '../config'
import { api, ApiError, OfflineError, publicRequest } from '../lib/api'
import { useAuth } from '../lib/auth'
import type { Department, User } from '../lib/types'
import { Button, colors, Field, Notice } from '../ui'

/** Turn local digits into E.164 with the default country code; keep a typed "+…" as is. */
function toE164(input: string): string {
  const trimmed = input.replace(/[\s\-()]/g, '')
  if (trimmed.startsWith('+')) return trimmed
  if (trimmed.startsWith('00')) return '+' + trimmed.slice(2)
  return DEFAULT_COUNTRY_CODE + trimmed.replace(/^0/, '')
}

function loginErrorMessage(e: unknown): string {
  if (e instanceof OfflineError) return 'No connection. Check your internet and try again.'
  if (!(e instanceof ApiError)) return 'Something went wrong. Please try again.'
  switch (e.code) {
    case 'INVALID_CREDENTIALS': {
      const left = e.extra.attemptsRemaining as number | undefined
      return left !== undefined ? `Wrong phone number or PIN. ${left} ${left === 1 ? 'attempt' : 'attempts'} left before the account locks.` : 'Wrong phone number or PIN.'
    }
    case 'ACCOUNT_LOCKED':
      return 'Too many wrong PINs. Ask your supervisor to unlock your account or reset your PIN.'
    case 'TOO_MANY_ATTEMPTS':
      return 'Too many attempts. Wait a few minutes and try again.'
    default:
      return e.message
  }
}

function Screen({ children }: { children: React.ReactNode }) {
  return (
    <KeyboardAvoidingView behavior="height" style={{ flex: 1, backgroundColor: colors.bg }}>
      <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
        {children}
      </ScrollView>
    </KeyboardAvoidingView>
  )
}

function Brand({ subtitle }: { subtitle: string }) {
  return (
    <View style={styles.brand}>
      <View style={styles.logo}>
        <Text style={styles.logoText}>SMC</Text>
      </View>
      <Text style={styles.title}>SMC Field Ops</Text>
      <Text style={styles.subtitle}>{subtitle}</Text>
    </View>
  )
}

const pinProps = { keyboardType: 'number-pad', secureTextEntry: true, maxLength: 4, autoComplete: 'off' } as const

export function LoginScreen({ reason, onRegister }: { reason?: string; onRegister: () => void }) {
  const { login } = useAuth()
  const [phone, setPhone] = useState('')
  const [pin, setPin] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit() {
    setError(null)
    setBusy(true)
    try {
      await login(toE164(phone), pin)
    } catch (e) {
      setError(loginErrorMessage(e))
      setPin('')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Screen>
      <Brand subtitle="Sign in with your phone number and PIN" />
      {reason && !error ? <Notice tone="amber">{reason}</Notice> : null}
      {error ? <Notice>{error}</Notice> : null}
      <Field label="Phone number" prefix={DEFAULT_COUNTRY_CODE} value={phone} onChangeText={setPhone} keyboardType="phone-pad" autoComplete="tel" placeholder="5X XXX XXXX" />
      <Field label="PIN" value={pin} onChangeText={(t) => setPin(t.replace(/\D/g, ''))} placeholder="4 digits" {...pinProps} />
      <Button title="Sign in" onPress={submit} loading={busy} disabled={!phone.trim() || pin.length !== 4} />
      <Pressable onPress={onRegister} style={styles.link} accessibilityRole="button">
        <Text style={styles.linkText}>New here? Register</Text>
      </Pressable>
    </Screen>
  )
}

export function RegisterScreen({ onBack }: { onBack: () => void }) {
  const [fullName, setFullName] = useState('')
  const [phone, setPhone] = useState('')
  const [pin, setPin] = useState('')
  const [confirm, setConfirm] = useState('')
  const [departmentId, setDepartmentId] = useState<string | null>(null)
  const [departments, setDepartments] = useState<Department[]>([])
  const [error, setError] = useState<ApiError | string | null>(null)
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(false)

  useEffect(() => {
    publicRequest<{ data: Department[] }>('GET', '/departments')
      .then((r) => setDepartments(r.data))
      .catch(() => {}) // department is optional
  }, [])

  async function submit() {
    if (pin !== confirm) return setError('The two PINs do not match.')
    setError(null)
    setBusy(true)
    try {
      await publicRequest('POST', '/auth/register', { phone: toE164(phone), fullName: fullName.trim(), pin, departmentId })
      setDone(true)
    } catch (e) {
      setError(e instanceof ApiError ? e : loginErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  if (done) {
    return (
      <Screen>
        <Brand subtitle="Registration received" />
        <Notice tone="green">An administrator will review your account. You can sign in once it's approved.</Notice>
        <Button title="Back to sign in" onPress={onBack} />
      </Screen>
    )
  }

  const fieldError = error instanceof ApiError ? error : null

  return (
    <Screen>
      <Brand subtitle="Create your account" />
      {typeof error === 'string' ? <Notice>{error}</Notice> : fieldError && !Object.keys(fieldError.fieldErrors).length ? <Notice>{fieldError.message}</Notice> : null}
      <Field label="Full name" value={fullName} onChangeText={setFullName} autoComplete="name" error={fieldError?.field('fullName')} />
      <Field label="Phone number" prefix={DEFAULT_COUNTRY_CODE} value={phone} onChangeText={setPhone} keyboardType="phone-pad" placeholder="5X XXX XXXX" error={fieldError?.field('phone')} />

      {departments.length > 0 && (
        <View style={{ marginBottom: 16 }}>
          <Text style={styles.label}>Team (optional)</Text>
          <View style={styles.chips}>
            {departments.map((d) => {
              const on = departmentId === d.id
              return (
                <Pressable key={d.id} onPress={() => setDepartmentId(on ? null : d.id)} style={[styles.chip, on && { borderColor: d.colorHex, backgroundColor: d.colorHex + '1A' }]}>
                  <View style={[styles.dot, { backgroundColor: d.colorHex }]} />
                  <Text style={styles.chipText}>{d.name}</Text>
                </Pressable>
              )
            })}
          </View>
        </View>
      )}

      <Field label="Choose a 4-digit PIN" value={pin} onChangeText={(t) => setPin(t.replace(/\D/g, ''))} hint="Avoid easy PINs like 1234 or 0000." error={fieldError?.field('pin')} {...pinProps} />
      <Field label="Repeat PIN" value={confirm} onChangeText={(t) => setConfirm(t.replace(/\D/g, ''))} {...pinProps} />
      <Button title="Register" onPress={submit} loading={busy} disabled={!fullName.trim() || !phone.trim() || pin.length !== 4 || confirm.length !== 4} />
      <Pressable onPress={onBack} style={styles.link} accessibilityRole="button">
        <Text style={styles.linkText}>Back to sign in</Text>
      </Pressable>
    </Screen>
  )
}

/** Admin-created accounts and PIN resets start with a temporary PIN that must be replaced. */
export function ChangePinScreen() {
  const { setUser, logout } = useAuth()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [error, setError] = useState<ApiError | string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit() {
    if (next !== confirm) return setError('The two new PINs do not match.')
    setError(null)
    setBusy(true)
    try {
      const res = await api.post<{ data: User }>('/auth/change-pin', { currentPin: current, newPin: next })
      setUser(res.data)
    } catch (e) {
      setError(e instanceof ApiError ? e : loginErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const fieldError = error instanceof ApiError ? error : null

  return (
    <Screen>
      <Brand subtitle="Set your own PIN" />
      <Notice tone="blue">You signed in with a temporary PIN. Choose a new one to continue.</Notice>
      {typeof error === 'string' ? <Notice>{error}</Notice> : fieldError && !Object.keys(fieldError.fieldErrors).length ? <Notice>{fieldError.message}</Notice> : null}
      <Field label="Temporary PIN" value={current} onChangeText={(t) => setCurrent(t.replace(/\D/g, ''))} {...pinProps} />
      <Field label="New PIN" value={next} onChangeText={(t) => setNext(t.replace(/\D/g, ''))} error={fieldError?.field('newPin')} hint="Avoid easy PINs like 1234 or 0000." {...pinProps} />
      <Field label="Repeat new PIN" value={confirm} onChangeText={(t) => setConfirm(t.replace(/\D/g, ''))} {...pinProps} />
      <Button title="Save PIN" onPress={submit} loading={busy} disabled={current.length !== 4 || next.length !== 4 || confirm.length !== 4} />
      <Pressable onPress={logout} style={styles.link} accessibilityRole="button">
        <Text style={styles.linkText}>Sign out</Text>
      </Pressable>
    </Screen>
  )
}

const styles = StyleSheet.create({
  scroll: { flexGrow: 1, justifyContent: 'center', padding: 24 },
  brand: { alignItems: 'center', marginBottom: 28 },
  logo: { width: 64, height: 64, borderRadius: 18, backgroundColor: colors.brand, alignItems: 'center', justifyContent: 'center' },
  logoText: { color: colors.white, fontWeight: '800', fontSize: 18 },
  title: { marginTop: 14, fontSize: 24, fontWeight: '700', color: colors.text },
  subtitle: { marginTop: 4, fontSize: 15, color: colors.muted, textAlign: 'center' },
  link: { alignItems: 'center', paddingVertical: 16 },
  linkText: { color: colors.brand, fontSize: 15, fontWeight: '600' },
  label: { fontSize: 14, fontWeight: '600', color: colors.text, marginBottom: 8 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { flexDirection: 'row', alignItems: 'center', gap: 6, borderWidth: 1, borderColor: colors.border, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 8, backgroundColor: colors.white },
  chipText: { fontSize: 14, color: colors.text },
  dot: { width: 10, height: 10, borderRadius: 5 },
})
