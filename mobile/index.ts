import { registerRootComponent } from 'expo'

// Registers the background location task before anything else, so Android
// can deliver fixes even when only the foreground service is alive.
import './src/lib/tracking'

import App from './src/App'

registerRootComponent(App)
