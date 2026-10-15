@extends('layout.v2')
@section('content')
    <div class="container">
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <h1>{{ $mainTitle }}</h1>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-lg-10 offset-lg-1">
                <div class="card">
                    <div class="card-header">
                        {{ $subTitle }}
                    </div>
                    <div class="card-body">
                        @if($disabled)
                            <div class="alert alert-warning" role="alert">
                                BKB management is disabled: no administrative password is configured on the server.
                            </div>
                        @else
                            <p>
                                BKB management needs its own administrative login. Your Firefly III credentials
                                cannot be used here.
                            </p>
                            @if($errors->any())
                                <div class="alert alert-danger" role="alert">
                                    <ul class="mb-0">
                                        @foreach($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            <form method="post" action="{{ route('bkb.login.post') }}" accept-charset="UTF-8">
                                <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                                <div class="mb-3">
                                    <label class="form-label" for="bkb-admin-password">Administrative password</label>
                                    <input type="password" class="form-control" id="bkb-admin-password" name="password"
                                           autocomplete="current-password"/>
                                </div>
                                <button type="submit" class="btn btn-primary">Log in</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
