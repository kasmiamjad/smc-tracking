import { StatusBar } from 'expo-status-bar'
import { useState } from 'react'
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { AuthProvider, useAuth } from './lib/auth'
import { ChangePinScreen, LoginScreen, RegisterScreen } from './screens/AuthScreens'
import { HomeScreen } from './screens/HomeScreen'
import { Button, colors } from './ui'

function Root() {
  const { state, retry } = useAuth()
  const [registering, setRegistering] = useState(false)

  switch (state.status) {
    case 'loading':
      return (
        <View style={styles.center}>
          <ActivityIndicator size="large" color={colors.brand} />
        </View>
      )
    case 'offline':
      return (
        <View style={[styles.center, { padding: 32 }]}>
          <Text style={styles.title}>No connection</Text>
          <Text style={styles.body}>You're still signed in, and location sharing keeps running if you're on shift. Updates are sent once you have signal.</Text>
          <Button title="Try again" onPress={retry} style={{ alignSelf: 'stretch', marginTop: 24 }} />
        </View>
      )
    case 'signedOut':
      return registering ? <RegisterScreen onBack={() => setRegistering(false)} /> : <LoginScreen reason={state.reason} onRegister={() => setRegistering(true)} />
    case 'signedIn':
      return state.user.mustChangePin ? <ChangePinScreen /> : <HomeScreen user={state.user} />
  }
}

export default function App() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <StatusBar style="dark" />
        <Root />
      </AuthProvider>
    </SafeAreaProvider>
  )
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg },
  title: { fontSize: 22, fontWeight: '700', color: colors.text },
  body: { fontSize: 15, color: colors.muted, textAlign: 'center', marginTop: 8, lineHeight: 22 },
})
