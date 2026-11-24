import { useQuery } from '@tanstack/react-query'
import { lazy, Suspense, type ReactNode } from 'react'
import { Navigate, Route, Routes, useLocation } from 'react-router'
import { AppShell } from './components/AppShell'
import { Spinner } from './components/ui'
import { api } from './lib/api'
import { isAdmin, useSession } from './lib/session'
import type { Home } from './lib/types'
import Auth from './screens/onboarding/Auth'
import Broker from './screens/onboarding/Broker'
import Channels from './screens/onboarding/Channels'
import Done from './screens/onboarding/Done'
import Plan from './screens/onboarding/Plan'
import Style from './screens/onboarding/Style'
import { TgCode, TgLinked, TgPassword, TgPhone, TgQr } from './screens/onboarding/Telegram'
import Welcome from './screens/onboarding/Welcome'
import { ForgotPassword, ResetPassword } from './screens/onboarding/Password'
import Landing from './screens/Landing'
import AlertsScreen from './screens/app/Alerts'
import BillingScreen from './screens/app/Billing'
import BrokersScreen from './screens/app/Brokers'
import ChannelsScreen from './screens/app/Channels'
import HomeScreen from './screens/app/Home'
import SettingsScreen from './screens/app/Settings'
import TradesScreen from './screens/app/Trades'
import DetailScreen from './screens/app/Detail'

// The admin console is only for staff: keep it out of the customer download.
const Admin = {
  Layout: lazy(() => import('./screens/admin/Admin').then((m) => ({ default: m.AdminLayout }))),
  Overview: lazy(() => import('./screens/admin/Overview')),
  Channels: lazy(() => import('./screens/admin/Admin').then((m) => ({ default: m.AdminChannels }))),
  Parser: lazy(() => import('./screens/admin/Admin').then((m) => ({ default: m.AdminParser }))),
  Review: lazy(() => import('./screens/admin/Admin').then((m) => ({ default: m.AdminReview }))),
  Users: lazy(() => import('./screens/admin/Admin').then((m) => ({ default: m.AdminUsers }))),
  Audit: lazy(() => import('./screens/admin/Admin').then((m) => ({ default: m.AdminAudit }))),
  User: lazy(() => import('./screens/admin/Admin').then((m) => ({ default: m.AdminUser }))),
  System: lazy(() => import('./screens/admin/Admin').then((m) => ({ default: m.AdminSystem }))),
  Email: lazy(() => import('./screens/admin/Email')),
}

function RequireUser({ admin, children }: { admin?: boolean; children: ReactNode }) {
  const { user, loading } = useSession()
  const loc = useLocation()
  if (loading) return <Spinner />
  if (!user) return <Navigate to="/login" replace state={{ from: loc.pathname }} />
  if (admin && !isAdmin(user)) return <Navigate to="/app" replace />
  return <>{children}</>
}

function Shell() {
  const unread = useQuery({ queryKey: ['home'], queryFn: () => api<Home>('home'), select: (h) => h.unread })
  return <AppShell badge={unread.data} />
}

export default function App() {
  return (
    <Suspense fallback={<Spinner />}>
      <Routes>
        <Route path="/" element={<Landing />} />
        <Route path="/welcome" element={<Welcome />} />
        <Route path="/signup" element={<Auth mode="signup" />} />
        <Route path="/login" element={<Auth mode="login" />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/reset-password" element={<ResetPassword />} />

        <Route path="/start/telegram" element={<RequireUser><TgPhone /></RequireUser>} />
        <Route path="/start/code" element={<RequireUser><TgCode /></RequireUser>} />
        <Route path="/start/password" element={<RequireUser><TgPassword /></RequireUser>} />
        <Route path="/start/qr" element={<RequireUser><TgQr /></RequireUser>} />
        <Route path="/start/linked" element={<RequireUser><TgLinked /></RequireUser>} />
        <Route path="/start/channels" element={<RequireUser><Channels /></RequireUser>} />
        <Route path="/start/broker" element={<RequireUser><Broker /></RequireUser>} />
        <Route path="/start/style" element={<RequireUser><Style /></RequireUser>} />
        <Route path="/start/plan" element={<RequireUser><Plan /></RequireUser>} />
        <Route path="/start/done" element={<RequireUser><Done /></RequireUser>} />

        <Route path="/app" element={<RequireUser><Shell /></RequireUser>}>
          <Route index element={<HomeScreen />} />
          <Route path="trades" element={<TradesScreen />} />
          <Route path="channels" element={<ChannelsScreen />} />
          <Route path="alerts" element={<AlertsScreen />} />
          <Route path="alerts/:id" element={<DetailScreen kind="alerts" />} />
          <Route path="trades/:id" element={<DetailScreen kind="trades" />} />
          <Route path="signals/:id" element={<DetailScreen kind="signals" />} />
          <Route path="settings" element={<SettingsScreen />} />
          <Route path="billing" element={<BillingScreen />} />
          <Route path="brokers" element={<BrokersScreen />} />
        </Route>

        <Route path="/admin" element={<RequireUser admin><Admin.Layout /></RequireUser>}>
          <Route index element={<Admin.Overview />} />
          <Route path="channels" element={<Admin.Channels />} />
          <Route path="parser" element={<Admin.Parser />} />
          <Route path="review" element={<Admin.Review />} />
          <Route path="users" element={<Admin.Users />} />
          <Route path="users/:id" element={<Admin.User />} />
          <Route path="audit" element={<Admin.Audit />} />
          <Route path="system" element={<Admin.System />} />
          <Route path="email" element={<Admin.Email />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
  )
}
