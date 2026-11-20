import { useNavigate } from 'react-router'
import { InstallCard } from '../../components/Install'
import { Button, Step } from '../../components/ui'
import { useSession } from '../../lib/session'
import { Confetti, TgArt } from './Telegram'

export default function Done() {
  const nav = useNavigate()
  const { user } = useSession()
  const n = user?.onboarding.channels ?? 0
  return (
    <Step
      back={false}
      art={<TgArt done />}
      title="You're all set"
      subtitle={`PipStream is now watching ${n} channel${n === 1 ? '' : 's'}. The next signal they post is copied to your practice account, and you'll get an alert.`}
      action={
        <Button block onClick={() => nav('/app', { replace: true })}>
          Go to my dashboard
        </Button>
      }
    >
      <Confetti />
      <InstallCard />
    </Step>
  )
}
