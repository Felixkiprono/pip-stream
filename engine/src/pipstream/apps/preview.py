"""Internal HTTP for the admin "parse preview" screen.

Laravel calls  POST http://intake:8001/parse-preview  {text, channel_id?, profile?}
over the private compose network. The port is never published and the only route is a
pure function of its input: it parses text and returns the result without storing or
publishing anything.
"""
from __future__ import annotations

from fastapi import FastAPI
from pydantic import BaseModel

from ..common.events import SignalSource
from ..common.service import Context
from ..services.parser.engine import ParserProfile


class PreviewIn(BaseModel):
    text: str
    channel_id: str | None = None
    reply_to_id: int | None = None
    profile: dict | None = None  # unsaved profile config to try


def build_app(par) -> FastAPI:
    app = FastAPI(title="pipstream-intake", docs_url=None, redoc_url=None, openapi_url=None)

    @app.get("/health")
    async def health():
        return {"ok": True}

    @app.post("/parse-preview")
    async def preview(body: PreviewIn):
        if body.profile is not None:
            profile = ParserProfile.model_validate(body.profile)
        else:
            profile = par.profiles.get(body.channel_id or "", ParserProfile())
        res = par.parser.parse(body.text, channel_id=body.channel_id or "preview",
                               source=SignalSource(message_id=0, reply_to_id=body.reply_to_id), profile=profile)
        if res.signal:
            return {"status": "parsed", "signal": res.signal.model_dump(mode="json", by_alias=True)}
        return {"status": "rejected", "reason": res.rejected.reason, "confidence": res.rejected.confidence}

    return app


async def serve_preview(c: Context, par) -> None:
    import uvicorn
    s = c.settings
    server = uvicorn.Server(uvicorn.Config(build_app(par), host=s.intake_http_host, port=s.intake_http_port,
                                           log_level="warning", access_log=False))
    server.install_signal_handlers = lambda: None  # the worker runtime owns SIGTERM
    task = server.serve()
    import asyncio
    stopper = asyncio.create_task(c.stop.wait())
    serving = asyncio.create_task(task)
    await asyncio.wait({stopper, serving}, return_when=asyncio.FIRST_COMPLETED)
    server.should_exit = True
    await serving
