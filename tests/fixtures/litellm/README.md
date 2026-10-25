# LiteLLM price feed fixture

`model_prices.json` is a subset of the real
`https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json`,
captured 2026-10-24 (ETag `36294be3dae0e7aa72694559befd616d155606eec8d89e4076a63fc09d2c1f40`).
Entries are copied whole with `jq`; none is hand-edited. Recapture rather than edit
if the feed shape changes.

Kept keys:
- `claude-sonnet-5-5`, `claude-haiku-4-5`, `claude-haiku-4-5-20251001`: Anthropic models we price (dated and undated haiku both exist upstream).
- `gpt-6-sol`, `gpt-5.6-sol`: Codex models we price (`litellm_provider: openai`, no 1h cache-write price).
- `bedrock/us-gov-east-1/anthropic.claude-sonnet-5-5`: a hosted copy of a Claude model, which the parser must ignore (provider `bedrock`).
- `azure/eu/text-embedding-3-small`: an unrelated non-chat model.

Verified feed facts:
- Prices are USD per token (`0.000002` = $2/MTok); small values are written in exponent form (`1E-7`).
- Anthropic entries carry `cache_creation_input_token_cost_above_1hr`; OpenAI entries do not, yet carry a non-zero `cache_creation_input_token_cost` although OpenAI bills no cache writes.
- The file is served as `text/plain` with an `ETag` header, about 3 MB.
