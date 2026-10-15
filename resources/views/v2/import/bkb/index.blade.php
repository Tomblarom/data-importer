@extends('layout.v2')
@section('content')
    <div class="container">
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <h1>{{ $mainTitle }}</h1>
            </div>
        </div>
        @if($errors->any())
            <div class="row mt-3">
                <div class="col-lg-10 offset-lg-1">
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif
        @if(session('status'))
            <div class="row mt-3">
                <div class="col-lg-10 offset-lg-1">
                    <div class="alert alert-success" role="alert">
                        {{ session('status') }}
                    </div>
                </div>
            </div>
        @endif
        @if(!$available)
            <div class="row mt-3">
                <div class="col-lg-10 offset-lg-1">
                    <div class="alert alert-warning" role="alert">
                        The BKB browser service is currently unavailable. Check the service status and try again later.
                    </div>
                </div>
            </div>
        @endif
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        {{ $subTitle }}: state
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">State</dt>
                            <dd class="col-sm-8">{{ $state }}</dd>
                            <dt class="col-sm-4">Bank session</dt>
                            <dd class="col-sm-8">{{ $authenticated ? 'authenticated' : 'not authenticated' }}</dd>
                            <dt class="col-sm-4">Automatic imports</dt>
                            <dd class="col-sm-8">{{ $botPaused ? 'paused' : 'active' }}</dd>
                            <dt class="col-sm-4">Last successful import</dt>
                            <dd class="col-sm-8">{{ $lastSuccess ?? 'never' }}</dd>
                            <dt class="col-sm-4">Next scheduled import</dt>
                            <dd class="col-sm-8">{{ $nextRun ?? 'unknown' }}</dd>
                            <dt class="col-sm-4">Configuration</dt>
                            <dd class="col-sm-8">{{ $configReady ? 'complete' : 'incomplete' }}</dd>
                            <dt class="col-sm-4">Pending import</dt>
                            <dd class="col-sm-8">{{ $pendingImport ? 'yes' : 'no' }}</dd>
                            <dt class="col-sm-4">Export range verified</dt>
                            <dd class="col-sm-8">{{ $rangeVerified ? 'yes' : 'not verified' }}</dd>
                            <dt class="col-sm-4">Browser keepalive</dt>
                            <dd class="col-sm-8">{{ $keepalive['strategy'] ?? 'unknown' }}, last
                                verified {{ $keepalive['last_verified'] ?? 'never' }}</dd>
                            @if(!empty($keepalive['observations']))
                                <dt class="col-sm-4">Keepalive notes</dt>
                                <dd class="col-sm-8">{{ is_array($keepalive['observations']) ? implode('; ', $keepalive['observations']) : $keepalive['observations'] }}</dd>
                            @endif
                            @if(null !== $lastError && '' !== trim((string) $lastError))
                                <dt class="col-sm-4">Last error</dt>
                                <dd class="col-sm-8">{{ $lastError }}</dd>
                            @endif
                        </dl>
                    </div>
                </div>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        Bank browser
                    </div>
                    <div class="card-body">
                        <p>
                            Log in on the bank page below by clicking the fields and typing. The view refreshes only
                            while live view is enabled and this page is visible.
                        </p>
                        <div class="mb-2">
                            <button type="button" class="btn btn-sm btn-secondary" id="bkb-open">Open browser</button>
                            <div class="form-check form-check-inline ms-2">
                                <input class="form-check-input" type="checkbox" id="bkb-live" value="1"/>
                                <label class="form-check-label" for="bkb-live">Live view</label>
                            </div>
                            <span class="ms-2 text-muted" id="bkb-browser-message"></span>
                        </div>
                        <div class="border p-1 mb-2">
                            <img id="bkb-frame" src="{{ route('bkb.frame') }}" width="{{ $viewportWidth }}"
                                 height="{{ $viewportHeight }}" alt="Bank browser view"
                                 style="max-width:100%;height:auto;display:block;cursor:crosshair;"/>
                        </div>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label" for="bkb-text">Type into the focused bank field</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="bkb-text" autocomplete="off"
                                           autocapitalize="off" spellcheck="false"/>
                                    <button type="button" class="btn btn-primary" id="bkb-send-text">Send</button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="btn-group" role="group" aria-label="Keys">
                                    <button type="button" class="btn btn-secondary" data-bkb-key="Enter">Enter</button>
                                    <button type="button" class="btn btn-secondary" data-bkb-key="Tab">Tab</button>
                                    <button type="button" class="btn btn-secondary" data-bkb-key="Backspace">
                                        Backspace
                                    </button>
                                </div>
                                <div class="btn-group ms-2" role="group" aria-label="Scroll">
                                    <button type="button" class="btn btn-secondary" data-bkb-scroll="400">Scroll
                                        down
                                    </button>
                                    <button type="button" class="btn btn-secondary" data-bkb-scroll="-400">Scroll
                                        up
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        Configuration
                    </div>
                    <div class="card-body">
                        <form method="post" action="{{ route('bkb.config.save') }}" accept-charset="UTF-8">
                            <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                            <div class="mb-3">
                                <label class="form-label" for="bkb-account-url">Account statement URL</label>
                                <input type="url" class="form-control" id="bkb-account-url" name="account_url"
                                       value="{{ old('account_url', $accountUrl) }}"
                                       placeholder="https://ebanking.bkb.ch/..."/>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="bkb-start-date">Start date (blank: all)</label>
                                    <input type="date" class="form-control" id="bkb-start-date" name="start_date"
                                           value="{{ old('start_date', $startDate) }}"/>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="bkb-end-date">End date (blank: today)</label>
                                    <input type="date" class="form-control" id="bkb-end-date" name="end_date"
                                           value="{{ old('end_date', $endDate) }}"/>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="bkb-interval">Interval (seconds)</label>
                                    <input type="number" class="form-control" id="bkb-interval" name="interval_seconds"
                                           min="3600" max="604800" value="{{ old('interval_seconds', $intervalSeconds) }}"/>
                                </div>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="bkb-paused" name="paused" value="1"
                                       @if(old('paused', $paused)) checked @endif />
                                <label class="form-check-label" for="bkb-paused">Pause automatic imports</label>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="bkb-import-config">Import configuration (JSON)</label>
                                <textarea class="form-control font-monospace" id="bkb-import-config" name="import_config"
                                          rows="8"
                                          placeholder="{}">{{ old('import_config', $importConfig) }}</textarea>
                                <div class="form-text">
                                    Paste a previously exported importer configuration here to reuse it for automated
                                    imports. To get one: fetch a sample CSV below, finish its column mapping, then use
                                    the download option on the configuration step and paste the file contents here (or
                                    pick the file below; it is read locally and never uploaded anywhere else).
                                </div>
                                <input type="file" class="form-control mt-2" id="bkb-import-config-file"
                                       accept="application/json,.json"/>
                            </div>
                            <button type="submit" class="btn btn-primary">Save configuration</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        Import actions
                    </div>
                    <div class="card-body">
                        <form method="post" action="{{ route('bkb.sample') }}" accept-charset="UTF-8" class="mb-3">
                            <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                            <p class="mb-2">
                                Fetch a sample CSV and start the normal column mapping flow. Uses the date range
                                above when filled in, otherwise the configured range.
                            </p>
                            <button type="submit" class="btn btn-secondary">Download sample CSV / start mapping
                            </button>
                        </form>
                        <hr/>
                        <form method="post" action="{{ route('bkb.sync') }}" accept-charset="UTF-8"
                              class="d-inline">
                            <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                            <button type="submit" class="btn btn-primary">Sync now</button>
                        </form>
                        @if($pendingImport)
                            <form method="post" action="{{ route('bkb.recover') }}" accept-charset="UTF-8"
                                  class="d-inline ms-2">
                                <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                                <button type="submit" class="btn btn-warning">Acknowledge failed import</button>
                            </form>
                            <p class="form-text mt-2 mb-0">
                                Acknowledges a verified terminal failed import: the journal is archived and
                                automatic imports stay paused so the mapping can be corrected. Nothing is
                                retried automatically.
                            </p>
                        @endif
                        @if($botPaused)
                            <form method="post" action="{{ route('bkb.resume') }}" accept-charset="UTF-8"
                                  class="d-inline ms-2">
                                <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                                <button type="submit" class="btn btn-secondary">Resume automatic imports</button>
                            </form>
                        @else
                            <form method="post" action="{{ route('bkb.pause') }}" accept-charset="UTF-8"
                                  class="d-inline ms-2">
                                <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                                <button type="submit" class="btn btn-secondary">Pause automatic imports</button>
                            </form>
                        @endif
                    </div>
                </div>
                <div class="card mt-3">
                    <div class="card-body">
                        <div class="btn-group btn-group-sm">
                            <a href="{{ route('index') }}" class="btn btn-secondary">Go back to the index</a>
                            <a href="{{ route('bkb.logout') }}" class="btn btn-secondary"
                               onclick="event.preventDefault();document.getElementById('bkb-logout-form').submit();">Log
                                out</a>
                        </div>
                        <form id="bkb-logout-form" method="post" action="{{ route('bkb.logout') }}" class="d-none">
                            <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('scripts')
    <script>
        (() => {
            const VIEWPORT_WIDTH = {{ $viewportWidth }};
            const VIEWPORT_HEIGHT = {{ $viewportHeight }};
            const frameUrl = @json(route('bkb.frame'));
            const openUrl = @json(route('bkb.browser.open'));
            const actionUrl = @json(route('bkb.browser.action'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            const frame = document.getElementById('bkb-frame');
            const live = document.getElementById('bkb-live');
            const message = document.getElementById('bkb-browser-message');
            const textInput = document.getElementById('bkb-text');
            let timer = null;

            const setMessage = (text) => {
                message.textContent = text;
            };

            const refreshFrame = () => {
                if (!live.checked || document.hidden) {
                    return;
                }
                frame.src = frameUrl + '?t=' + Date.now();
            };

            const startPolling = () => {
                stopPolling();
                timer = window.setInterval(refreshFrame, 1500);
            };

            const stopPolling = () => {
                if (null !== timer) {
                    window.clearInterval(timer);
                    timer = null;
                }
            };

            live.addEventListener('change', () => {
                if (live.checked) {
                    startPolling();
                    refreshFrame();
                } else {
                    stopPolling();
                }
            });
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && live.checked) {
                    refreshFrame();
                }
            });

            const postJson = async (url, payload) => {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(payload),
                    cache: 'no-store'
                });
                if (!response.ok) {
                    throw new Error('Request failed');
                }
                return response.json();
            };

            document.getElementById('bkb-open').addEventListener('click', async () => {
                setMessage('Opening...');
                try {
                    await postJson(openUrl, {});
                    setMessage('Browser opened.');
                    refreshFrame();
                } catch (e) {
                    setMessage('Could not open the bank browser.');
                }
            });

            frame.addEventListener('click', async (event) => {
                const rect = frame.getBoundingClientRect();
                if (0 === rect.width || 0 === rect.height) {
                    return;
                }
                const x = Math.round((event.clientX - rect.left) * VIEWPORT_WIDTH / rect.width);
                const y = Math.round((event.clientY - rect.top) * VIEWPORT_HEIGHT / rect.height);
                setMessage('');
                try {
                    await postJson(actionUrl, {type: 'click', x: x, y: y});
                    refreshFrame();
                } catch (e) {
                    setMessage('Click was not accepted.');
                }
            });

            document.getElementById('bkb-send-text').addEventListener('click', async () => {
                const text = textInput.value;
                if ('' === text) {
                    return;
                }
                setMessage('');
                try {
                    await postJson(actionUrl, {type: 'text', text: text});
                    textInput.value = '';
                    refreshFrame();
                } catch (e) {
                    setMessage('Text was not accepted.');
                }
            });

            document.querySelectorAll('[data-bkb-key]').forEach((button) => {
                button.addEventListener('click', async () => {
                    setMessage('');
                    try {
                        await postJson(actionUrl, {type: 'key', key: button.dataset.bkbKey});
                        refreshFrame();
                    } catch (e) {
                        setMessage('Key was not accepted.');
                    }
                });
            });

            document.querySelectorAll('[data-bkb-scroll]').forEach((button) => {
                button.addEventListener('click', async () => {
                    setMessage('');
                    try {
                        await postJson(actionUrl, {
                            type: 'scroll',
                            delta_y: parseInt(button.dataset.bkbScroll, 10)
                        });
                        refreshFrame();
                    } catch (e) {
                        setMessage('Scroll was not accepted.');
                    }
                });
            });

            const configFile = document.getElementById('bkb-import-config-file');
            const configText = document.getElementById('bkb-import-config');
            configFile.addEventListener('change', () => {
                if (0 === configFile.files.length) {
                    return;
                }
                const reader = new FileReader();
                reader.onload = () => {
                    configText.value = reader.result;
                };
                reader.readAsText(configFile.files[0]);
            });

            // Open (or restore) the bank browser once on page load.
            postJson(openUrl, {}).then(() => {
                refreshFrame();
            }).catch(() => {
                setMessage('Bank browser is not open yet; use "Open browser".');
            });
        })();
    </script>
@endsection
