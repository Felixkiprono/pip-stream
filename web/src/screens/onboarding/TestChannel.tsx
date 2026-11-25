import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { ExternalLink, Send } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router'
import { CopyBox, TestSteps, testText, useSelfTest } from '../../components/ChannelCheck'
import { Button, ErrorNote, Spinner, Step } from '../../components/ui'
import { api } from '../../lib/api'
import { ago } from '../../lib/format'
import type { MyChannel } from '../../lib/types'

type Recent = { id: string; status: 'parsed' | 'rejected'; at: string; posted_at: string | null; text: string; read_as: string }

/** Onboarding: post a test signal in your channel and watch PipStream read it and size the
 *  practice trade, live. Nothing is traded. Channels you can't post in show their latest posts. */
export default function TestChannel() {
  const nav = useNavigate()
  const channels = useQuery({ queryKey: ['channels'], queryFn: () => api<{ channels: MyChannel[] }>('channels') })
  const list = channels.data?.channels ?? []
  const [id, setId] = useState('')
  useEffect(() => void (!id && list[0] && setId(list[0].id)), [id, list])
  const ch = list.find((c) => c.id === id)
  const [mode, setMode] = useState<'post' | 'watch'>('post')
  const st = useSelfTest(id)
  const recent = useQuery({
    queryKey: ['channel-check', id],
    queryFn: () => api<{ recent: Recent[] }>(`channels/${id}/check`),
    enabled: !!id && mode === 'watch',
    refetchInterval: 10000,
  })
  const ok = st.test?.done && st.test.state !== 'expired' && st.test.state !== 'wrong_channel'

  return (
    <Step
      step={4}
      back="/start/channels"
      title="Test your channel"
      subtitle="Post a test signal in your channel and watch PipStream read it and build the trade, live. It's a practice run: nothing is traded."
      action={
        <Button block variant={ok ? 'primary' : 'ghost'} onClick={() => nav('/start/broker')}>
          {ok ? 'Looks good, continue' : 'Skip for now'}
        </Button>
      }
    >
      {channels.isLoading ? (
        <Spinner />
      ) : !list.length ? (
        <ErrorNote>Pick a channel first.</ErrorNote>
      ) : (
        <>
          {list.length > 1 && (
            <div className="-mx-5 flex gap-2 overflow-x-auto px-5 pb-1">
              {list.map((c) => (
                <button key={c.id} onClick={() => setId(c.id)} className={clsx('shrink-0 rounded-full border-2 px-4 py-2 text-sm font-semibold', id === c.id ? 'border-brand bg-brand-soft text-brand' : 'border-line')}>
                  {c.title}
                </button>
              ))}
            </div>
          )}
          <div className="grid grid-cols-2 gap-1 rounded-2xl bg-surface-2 p-1">
            {(['post', 'watch'] as const).map((m) => (
              <button key={m} onClick={() => setMode(m)} className={clsx('rounded-xl py-2 text-sm font-semibold', mode === m ? 'bg-surface shadow-card' : 'text-ink-3')}>
                {m === 'post' ? 'I can post in it' : "I can't post in it"}
              </button>
            ))}
          </div>

          {mode === 'post' ? (
            !st.test || st.test.state === 'expired' ? (
              <div className="space-y-3 rounded-3xl bg-surface p-4">
                <ol className="list-decimal space-y-1 pl-5 text-[15px] text-ink-2">
                  <li>Get a one-time test code.</li>
                  <li>Post it in <b>{ch?.title}</b> from Telegram.</li>
                  <li>Watch it arrive here, read and turned into a practice trade.</li>
                </ol>
                <Button block busy={st.busy} onClick={() => st.start(false)}>
                  <Send className="size-4" /> Get my test code
                </Button>
              </div>
            ) : (
              <div className="space-y-3">
                <CopyBox text={testText(st.test.code, st.sample)} onNew={() => st.start(false)} busy={st.busy} />
                {ch?.username && (
                  <a href={`https://t.me/${ch.username}`} target="_blank" rel="noreferrer" className="flex min-h-11 items-center justify-center gap-2 rounded-2xl bg-tg px-4 text-sm font-semibold text-white">
                    Open {ch.title} in Telegram <ExternalLink className="size-4" />
                  </a>
                )}
                <p className="text-xs text-ink-3">You can also post a signal in your own style; keep the code PS-{st.test.code} anywhere in it. A post with the code is never copied for anyone else.</p>
                <TestSteps t={st.test} />
              </div>
            )
          ) : (
            <div className="space-y-2">
              <p className="text-[15px] text-ink-2">No problem. Here is what PipStream has read from {ch?.title} so far. New posts show up here as they arrive.</p>
              {recent.isLoading ? (
                <Spinner />
              ) : recent.data?.recent.length ? (
                <ul className="divide-y divide-line rounded-2xl border border-line bg-surface text-sm">
                  {recent.data.recent.map((s) => (
                    <li key={s.id} className="p-3">
                      <span className="flex items-center gap-2">
                        <span className={clsx('size-2 shrink-0 rounded-full', s.status === 'parsed' ? 'bg-up' : 'bg-line')} />
                        <span className="min-w-0 flex-1 truncate font-semibold">{s.read_as}</span>
                        <span className="shrink-0 text-ink-3">{ago(s.posted_at ?? s.at)}</span>
                      </span>
                      {s.text && <span className="mt-0.5 block truncate text-ink-3">{s.text}</span>}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="rounded-3xl bg-surface-2 p-5 text-center text-ink-2">Nothing posted since you added it. You can carry on; the next signal is copied to your practice account and shows under Trades.</p>
              )}
            </div>
          )}
          <ErrorNote>{st.err}</ErrorNote>
        </>
      )}
    </Step>
  )
}
