import { useQuery } from "@tanstack/react-query";
import clsx from "clsx";
import { Check, CheckCircle2, Copy, Download, Loader2 } from "lucide-react";
import { useEffect, useState } from "react";
import type { ApiError } from "../lib/api";
import { api, post } from "../lib/api";
import type { Broker } from "../lib/types";
import { QuickSetup } from "./QuickSetup";
import { Button, ErrorNote } from "./ui";

type PairStatus = {
    ea_url: string;
    seen: null | { platform: "mt4" | "mt5"; company: string };
};

/** The address the EA must reach (baked into the download), and whether a MetaTrader from here has reached it. */
export function usePairStatus(poll = true) {
    return useQuery({
        queryKey: ["pair-status"],
        queryFn: () => api<PairStatus>("brokers/pair/status"),
        refetchInterval: poll ? 2000 : false,
    });
}

/** MetaTrader on a Mac runs inside Wine: its "Open Data Folder" opens a Windows-style window that won't take a drag from Finder. */
export const isMac =
    typeof navigator !== "undefined" &&
    /Mac/i.test(navigator.userAgent) &&
    !/iPhone|iPad/i.test(navigator.userAgent);

export function macExpertsPath(mt: 4 | 5) {
    return mt === 5
        ? "~/Library/Application Support/net.metaquotes.wine.metatrader5/drive_c/Program Files/MetaTrader 5/MQL5/Experts"
        : "~/Library/Application Support/net.metaquotes.wine.metatrader4/drive_c/Program Files (x86)/MetaTrader 4/MQL4/Experts";
}

/** Where to put the downloaded file, said the way that works on this computer. */
export function InstallHint({ mt, source }: { mt: 4 | 5; source: boolean }) {
    return isMac ? (
        <>
            <p className="text-sm text-ink-2">
                On a Mac: open <b>Finder</b>, press <b>Cmd+Shift+G</b>, paste
                this folder, and drag the downloaded file into it:
            </p>
            <CopyRow value={macExpertsPath(mt)} />
            <p className="text-sm text-ink-2">
                Then in MetaTrader, right-click <b>Expert Advisors → Refresh</b>{" "}
                in the Navigator.
                {source && (
                    <>
                        {" "}
                        If PipStream doesn't appear, click <b>IDE</b> in
                        MetaTrader's toolbar, open <b>Experts → PipStream</b>{" "}
                        and press <b>Compile</b>.
                    </>
                )}
            </p>
        </>
    ) : (
        <p className="text-sm text-ink-2">
            In MetaTrader {mt}:{" "}
            <b>File → Open Data Folder → MQL{mt} → Experts</b>, drop the file
            in, then right-click <b>Expert Advisors → Refresh</b>.
            {source && (
                <>
                    {" "}
                    If PipStream doesn't appear, open the file in MetaEditor and
                    press <b>Compile</b>.
                </>
            )}
        </p>
    );
}

/** Seconds since mount, to show troubleshooting only after a fair wait. */
export function useElapsed() {
    const [s, setS] = useState(0);
    useEffect(() => {
        const t = window.setInterval(() => setS((x) => x + 1), 1000);
        return () => window.clearInterval(t);
    }, []);
    return s;
}

/** Ready-built EA when it was built for this address, else the source with the address filled in (MetaTrader compiles it). */
function useEaFile(mt: 4 | 5, eaUrl: string | undefined) {
    return useQuery({
        queryKey: ["ea-file", mt, eaUrl],
        staleTime: Infinity,
        enabled: !!eaUrl,
        queryFn: async () => {
            // a ready-built EA has the source's default address (local for now), so only offer it for that address
            if (eaUrl !== "http://127.0.0.1:8000")
                return `/ea/PipStream.mq${mt}`;
            const built = `/ea/PipStream.ex${mt}`;
            try {
                const r = await fetch(built, { method: "HEAD" });
                if (
                    r.ok &&
                    !(r.headers.get("content-type") ?? "").includes("text/html")
                )
                    return built;
            } catch {
                /* fall back to the source */
            }
            return `/ea/PipStream.mq${mt}`;
        },
    });
}

/** Connect MetaTrader in 4 steps: download, allow the address, drop on a chart, type the code. */
export function EaPair({ onPaired }: { onPaired: (b: Broker) => void }) {
    const [mt, setMt] = useState<4 | 5>(5);
    const status = usePairStatus();
    const eaUrl = status.data?.ea_url ?? window.location.origin;
    const file =
        useEaFile(mt, status.data?.ea_url).data ?? `/ea/PipStream.mq${mt}`;
    const seen = status.data?.seen;
    const waited = useElapsed();

    return (
        <div className="space-y-5">
            <div
                className="grid grid-cols-2 gap-2 rounded-2xl bg-surface-2 p-1"
                role="radiogroup"
                aria-label="Platform"
            >
                {([5, 4] as const).map((p) => (
                    <button
                        key={p}
                        type="button"
                        role="radio"
                        aria-checked={mt === p}
                        onClick={() => setMt(p)}
                        className={clsx(
                            "min-h-11 rounded-xl text-sm font-semibold",
                            mt === p
                                ? "bg-surface text-ink shadow-card"
                                : "text-ink-3",
                        )}
                    >
                        MetaTrader {p}
                    </button>
                ))}
            </div>

            <QuickSetup mt={mt} onLinked={onPaired} />

            <details className="group">
                <summary className="cursor-pointer text-sm font-semibold text-ink-3 underline">
                    Or set it up by hand (4 steps)
                </summary>
                <div className="mt-4 space-y-5">
                    <ol className="space-y-5">
                        <Li n={1} title="Download PipStream and open it">
                            <a
                                href={file}
                                download
                                className="inline-flex min-h-11 items-center gap-2 rounded-2xl bg-brand px-4 text-sm font-semibold text-brand-ink"
                            >
                                <Download className="size-4" />{" "}
                                {file.split("/").pop()}
                            </a>
                            <InstallHint
                                mt={mt}
                                source={file.includes(".mq")}
                            />
                            <p className="text-xs text-ink-3">
                                Already set up for {eaUrl}. Nothing to type in
                                the EA's settings.
                            </p>
                        </Li>
                        <Li n={2} title="Allow PipStream once">
                            <p className="text-sm text-ink-2">
                                <b>Tools → Options → Expert Advisors</b>: tick{" "}
                                <b>
                                    Allow{" "}
                                    {mt === 4 ? "automated" : "algorithmic"}{" "}
                                    trading
                                </b>{" "}
                                and <b>Allow WebRequest for listed URL</b>, and
                                add:
                            </p>
                            <CopyRow value={eaUrl} />
                        </Li>
                        <Li n={3} title="Drag PipStream onto any chart">
                            <p className="text-sm text-ink-2">
                                Press OK, and turn on{" "}
                                <b>
                                    {mt === 4 ? "AutoTrading" : "Algo Trading"}
                                </b>{" "}
                                in the toolbar. A 6-digit code appears in big
                                gold text on the chart. Had an older PipStream
                                on a chart? Remove it first, then drag this new
                                one on.
                            </p>
                        </Li>
                        <Li n={4} title="Type the code here">
                            <CodeForm onLinked={onPaired} />
                        </Li>
                    </ol>

                    <div
                        className="rounded-2xl border border-line bg-surface-2 p-3 text-sm"
                        aria-live="polite"
                    >
                        {seen ? (
                            <p className="flex items-start gap-2 font-semibold text-up">
                                <CheckCircle2 className="mt-0.5 size-5 shrink-0" />
                                <span>
                                    Your MetaTrader{" "}
                                    {seen.platform === "mt4" ? "4" : "5"}
                                    {seen.company
                                        ? ` (${seen.company})`
                                        : ""}{" "}
                                    reached PipStream.
                                    <span className="block font-normal text-ink-2">
                                        Type the 6-digit code shown on its chart
                                        above.
                                    </span>
                                </span>
                            </p>
                        ) : (
                            <>
                                <p className="flex items-center gap-2 text-ink-2">
                                    <Loader2 className="size-4 shrink-0 animate-spin text-brand" />{" "}
                                    Waiting for your MetaTrader to reach {eaUrl}
                                    …
                                </p>
                                {waited > 20 && (
                                    <Troubleshoot mt={mt} eaUrl={eaUrl} />
                                )}
                            </>
                        )}
                    </div>
                </div>
            </details>
        </div>
    );
}

/** The 6-digit code from the chart. With brokerId, it links that existing row instead of adding one. */
export function CodeForm({
    brokerId,
    onLinked,
}: {
    brokerId?: string;
    onLinked: (b: Broker) => void;
}) {
    const [code, setCode] = useState("");
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState<ApiError | null>(null);
    async function submit(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true);
        setErr(null);
        try {
            const r = await post<{ broker: Broker }>("brokers/pair", {
                code: code.replace(/\D/g, ""),
                broker_id: brokerId,
            });
            onLinked(r.broker);
        } catch (e) {
            setErr(e as ApiError);
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <form onSubmit={submit} className="flex gap-2">
                <input
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    placeholder="123 456"
                    maxLength={7}
                    value={code}
                    onChange={(e) =>
                        setCode(e.target.value.replace(/[^\d ]/g, ""))
                    }
                    aria-label="Code from your chart"
                    className="min-h-12 w-full min-w-0 rounded-2xl border border-line bg-surface px-4 text-center font-mono text-xl tracking-[0.3em] tabular"
                />
                <Button
                    type="submit"
                    busy={busy}
                    disabled={code.replace(/\D/g, "").length !== 6}
                >
                    Link
                </Button>
            </form>
            {err && <ErrorNote>{err.field("code") ?? err.message}</ErrorNote>}
        </>
    );
}

/** What usually stops the EA reaching us, in the order people hit it. */
export function Troubleshoot({ mt, eaUrl }: { mt: 4 | 5; eaUrl: string }) {
    return (
        <div className="mt-3 border-t border-line pt-3">
            <p className="font-semibold">Not reaching us yet? Check:</p>
            <ul className="mt-1 list-disc space-y-1 pl-5 text-ink-2">
                <li>
                    The chart's top left says <b>PipStream 1.10</b> or newer. An
                    older copy may point to another address: remove it and drag
                    on the new download.
                </li>
                <li>
                    <b>Tools → Options → Expert Advisors</b> lists{" "}
                    <b>{eaUrl}</b> exactly, with <b>Allow WebRequest</b> ticked.
                </li>
                <li>
                    <b>{mt === 4 ? "AutoTrading" : "Algo Trading"}</b> is green
                    in the toolbar.
                </li>
                <li>
                    The message at the top left of the chart says what's wrong,
                    if anything.
                </li>
            </ul>
        </div>
    );
}

function Li({
    n,
    title,
    children,
}: {
    n: number;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <li className="flex gap-3">
            <span className="grid size-7 shrink-0 place-items-center rounded-full bg-brand-soft text-sm font-bold text-brand">
                {n}
            </span>
            <div className="min-w-0 flex-1 space-y-2">
                <h3 className="font-semibold">{title}</h3>
                {children}
            </div>
        </li>
    );
}

export function CopyRow({ value }: { value: string }) {
    const [copied, setCopied] = useState(false);
    return (
        <div className="flex min-w-0 items-center gap-2 rounded-xl border border-line bg-surface px-3 py-2">
            <code className="min-w-0 flex-1 truncate font-mono text-sm select-all">
                {value}
            </code>
            <button
                type="button"
                className="inline-flex shrink-0 items-center gap-1 text-sm font-semibold text-brand"
                onClick={async () => {
                    try {
                        await navigator.clipboard.writeText(value);
                        setCopied(true);
                        setTimeout(() => setCopied(false), 1500);
                    } catch {
                        /* clipboard blocked: the text is selectable */
                    }
                }}
            >
                {copied ? (
                    <Check className="size-4" />
                ) : (
                    <Copy className="size-4" />
                )}{" "}
                {copied ? "Copied" : "Copy"}
            </button>
        </div>
    );
}
