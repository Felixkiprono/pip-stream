import uvicorn

from ...common.settings import Settings

s = Settings.from_env()
uvicorn.run("pipstream.services.core_api.app:app", host=s.api_host, port=s.api_port, proxy_headers=True)
