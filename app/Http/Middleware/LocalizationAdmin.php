<?php

namespace App\Http\Middleware;

use App\Models\ConfigGlobal;
use App\Models\Language;
use Closure;

class LocalizationAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {

        $locale_default = ConfigGlobal::first()->locale;
        $locale_config  = config('app.locale');
        $locale_array   = Language::where('status', 1)->pluck('code')->toArray();
        $locale         = (in_array($locale_default, $locale_array)) ? $locale_default : $locale_config;
        app()->setLocale($locale);
        return $next($request);
    }
}
