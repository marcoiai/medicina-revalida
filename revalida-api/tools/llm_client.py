from __future__ import annotations

import os
from dataclasses import dataclass
from typing import Any

try:
    from openai import OpenAI
except ImportError:  # pragma: no cover - optional dependency
    OpenAI = None

try:
    from google import genai
    from google.genai import types
except ImportError:  # pragma: no cover - optional dependency
    genai = None
    types = None


OPENAI_DEFAULT_MODEL = os.getenv("OPENAI_MODEL", "gpt-4.1-mini")
GEMINI_DEFAULT_MODEL = os.getenv("GEMINI_MODEL", "gemini-2.5-flash")


def normalize_provider_name(value: str | None) -> str:
    provider = (value or os.getenv("QUESTION_AI_PROVIDER", "openai")).strip().lower()
    if provider in {"gpt", "chatgpt"}:
        return "openai"
    if provider in {"google", "gemini"}:
        return "gemini"
    return provider or "openai"


def default_model_for_provider(provider: str) -> str:
    return OPENAI_DEFAULT_MODEL if provider == "openai" else GEMINI_DEFAULT_MODEL


@dataclass
class LLMClient:
    provider: str | None = None

    def __post_init__(self) -> None:
        self.provider = normalize_provider_name(self.provider)

        if self.provider == "openai":
            api_key = os.getenv("OPENAI_API_KEY")
            if not api_key:
                raise SystemExit("OPENAI_API_KEY não configurada para usar OpenAI.")
            if OpenAI is None:
                raise SystemExit(
                    "Dependência openai não instalada. Rode: pip install -r tools/requirements-ai.txt"
                )
            self.client: Any = OpenAI(api_key=api_key)
            return

        if self.provider == "gemini":
            api_key = os.getenv("GEMINI_API_KEY") or os.getenv("GOOGLE_API_KEY")
            if not api_key:
                raise SystemExit("GEMINI_API_KEY (ou GOOGLE_API_KEY) não configurada.")
            if genai is None or types is None:
                raise SystemExit(
                    "Dependência Gemini não instalada. Rode: pip install -r tools/requirements-ai.txt"
                )
            self.client = genai.Client(api_key=api_key)
            return

        raise SystemExit(
            f"QUESTION_AI_PROVIDER inválido: {self.provider}. Use openai ou gemini."
        )

    def generate_text(
        self,
        prompt: str,
        *,
        model: str | None = None,
        temperature: float | None = None,
        top_p: float | None = None,
        top_k: int | None = None,
        max_output_tokens: int | None = None,
        response_mime_type: str | None = None,
    ) -> str:
        if self.provider == "openai":
            kwargs: dict[str, Any] = {
                "model": model or default_model_for_provider("openai"),
                "input": prompt,
            }
            if max_output_tokens is not None:
                kwargs["max_output_tokens"] = max_output_tokens

            response = self.client.responses.create(**kwargs)
            return getattr(response, "output_text", "") or ""

        assert types is not None
        config_kwargs: dict[str, Any] = {}
        if temperature is not None:
            config_kwargs["temperature"] = temperature
        if top_p is not None:
            config_kwargs["top_p"] = top_p
        if top_k is not None:
            config_kwargs["top_k"] = top_k
        if max_output_tokens is not None:
            config_kwargs["max_output_tokens"] = max_output_tokens
        if response_mime_type is not None:
            config_kwargs["response_mime_type"] = response_mime_type

        response = self.client.models.generate_content(
            model=model or default_model_for_provider("gemini"),
            contents=prompt,
            config=types.GenerateContentConfig(**config_kwargs),
        )
        return getattr(response, "text", "") or ""
