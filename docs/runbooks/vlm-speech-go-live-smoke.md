# VLM + Speech v2 go-live smoke verification

Run BOTH procedures against the real project BEFORE enabling either kill switch.
Each costs a few cents. Record every observed value in the table at the bottom.

## 1. Gemini generateContent smoke (EU rep endpoint)

php artisan tinker
>>> $client = app(\App\Platform\Enrichment\VlmVerification\Http\GeminiVlmClient::class);
>>> $client->isConfigured();        // must be true
>>> $frame = new \App\Platform\Enrichment\VlmVerification\Requests\VlmFrame('FRAME_1', 1000, file_get_contents(storage_path('app/smoke/test-frame.jpg')), 'image/jpeg');
>>> $cand  = new \App\Platform\Enrichment\VlmVerification\Requests\VlmCandidate('P1', 1, 'Smoke Test Serum', 'SmokeBrand', null, [], null, null);
>>> $req   = new \App\Platform\Enrichment\VlmVerification\Requests\VlmRequest([$frame], [$cand], 'smoke caption', '', 'smoke prompt');
>>> $result = $client->verify($req);
>>> $result->finishReason;          // expect 'STOP'
>>> $result->json;                  // expect schema-valid: outcome + exactly 1 verdict for P1
>>> $result->promptTokens;          // record; sanity: one MEDIUM image ≈ 560 tokens + text

Verify while it runs (from the HTTP log/telemetry): the request went to
aiplatform.eu.rep.googleapis.com, the responseSchema round-tripped (schema-valid JSON came
back), and the part-level media_resolution + thinking_level fields were ACCEPTED (no 400
INVALID_ARGUMENT — if casing was wrong, fix the client constants and re-run).

## 2. Speech v2 recognize smoke (EU endpoint)

php artisan tinker
>>> $speech = app(\App\Platform\Enrichment\Http\GoogleSpeechV2Client::class);
>>> $speech->isConfigured();        // must be true
>>> $flac = file_get_contents(storage_path('app/smoke/test-de-en.flac'));  // ≤55s mono 16k FLAC with German+English speech
>>> $r = $speech->recognize($flac, ['SmokeBrand']);
>>> $r->results;                    // expect ≥1 transcript; languageCode present per result
>>> $r->billedSeconds;              // record

Verify: endpoint eu-speech.googleapis.com, model chirp_3 accepted, languageCodes ["auto"]
accepted, the inline adaptation phrase set accepted (no INVALID_ARGUMENT), and the detected
languageCode matches the dominant language of the sample.

## Pin table (fill in, commit the runbook update)

| Observed | Value | Date |
|---|---|---|
| Gemini model id served |  |  |
| promptTokens for 1 MEDIUM image + text |  |  |
| thinking tokens at LOW |  |  |
| Speech billedSeconds for the sample |  |  |
| Detected languageCode |  |  |
| Any casing/field corrections needed |  |  |
