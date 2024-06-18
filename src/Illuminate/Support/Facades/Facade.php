<?php

namespace Illuminate\Support\Facades;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Js;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\Fake;
use Mockery;
use Mockery\LegacyMockInterface;
use RuntimeException;

abstract class Facade
{
    /**
     * The application instance being facaded.
     *
     * @var \Illuminate\Contracts\Foundation\Application|null
     */
    protected static $app;

    /**
     * @var \Closure|null
     */
    protected static $appResolver = null;

    /**
     * The resolved object instances.
     *
     * @var array
     */
    protected static $resolvedInstance;

    protected static $facadeCacheResolver = null;

    /**
     * Indicates if the resolved instance should be cached.
     *
     * @var bool
     */
    protected static $cached = true;

    /**
     * Run a Closure when the facade has been resolved.
     *
     * @param  \Closure  $callback
     * @return void
     */
    public static function resolved(Closure $callback)
    {
        $accessor = static::getFacadeAccessor();

        if (static::getFacadeApplication()->resolved($accessor) === true) {
            $callback(static::getFacadeRoot(), static::getFacadeApplication());
        }

        static::getFacadeApplication()->afterResolving($accessor, function ($service, $app) use ($callback) {
            $callback($service, $app);
        });
    }

    /**
     * Convert the facade into a Mockery spy.
     *
     * @return \Mockery\MockInterface
     */
    public static function spy()
    {
        if (! static::isMock()) {
            $class = static::getMockableClass();

            return tap($class ? Mockery::spy($class) : Mockery::spy(), function ($spy) {
                static::swap($spy);
            });
        }
    }

    /**
     * Initiate a partial mock on the facade.
     *
     * @return \Mockery\MockInterface
     */
    public static function partialMock()
    {
        $name = static::getFacadeAccessor();

        $mock = static::isMock()
            ? static::getResolvedInstance()[$name]
            : static::createFreshMockInstance();

        return $mock->makePartial();
    }

    /**
     * Initiate a mock expectation on the facade.
     *
     * @return \Mockery\Expectation
     */
    public static function shouldReceive()
    {
        $name = static::getFacadeAccessor();

        $mock = static::isMock()
            ? static::getResolvedInstance()[$name]
            : static::createFreshMockInstance();

        return $mock->shouldReceive(...func_get_args());
    }

    /**
     * Initiate a mock expectation on the facade.
     *
     * @return \Mockery\Expectation
     */
    public static function expects()
    {
        $name = static::getFacadeAccessor();

        $mock = static::isMock()
            ? static::getResolvedInstance()[$name]
            : static::createFreshMockInstance();

        return $mock->expects(...func_get_args());
    }

    /**
     * Create a fresh mock instance for the given class.
     *
     * @return \Mockery\MockInterface
     */
    protected static function createFreshMockInstance()
    {
        return tap(static::createMock(), function ($mock) {
            static::swap($mock);

            $mock->shouldAllowMockingProtectedMethods();
        });
    }

    /**
     * Create a fresh mock instance for the given class.
     *
     * @return \Mockery\MockInterface
     */
    protected static function createMock()
    {
        $class = static::getMockableClass();

        return $class ? Mockery::mock($class) : Mockery::mock();
    }

    /**
     * Determines whether a mock is set as the instance of the facade.
     *
     * @return bool
     */
    protected static function isMock()
    {
        $name = static::getFacadeAccessor();

        return isset(static::getResolvedInstance()[$name]) &&
            static::getResolvedInstance()[$name] instanceof LegacyMockInterface;
    }

    /**
     * Get the mockable class for the bound instance.
     *
     * @return string|null
     */
    protected static function getMockableClass()
    {
        if ($root = static::getFacadeRoot()) {
            return get_class($root);
        }
    }

    /**
     * Hotswap the underlying instance behind the facade.
     *
     * @param  mixed  $instance
     * @return void
     */
    public static function swap($instance)
    {
        static::addResolvedInstance(static::getFacadeAccessor(), $instance);

        if (static::getFacadeApplication() !== null) {
            static::getFacadeApplication()->instance(static::getFacadeAccessor(), $instance);
        }
    }

    /**
     * Determines whether a "fake" has been set as the facade instance.
     *
     * @return bool
     */
    protected static function isFake()
    {
        $name = static::getFacadeAccessor();

        return isset(static::getResolvedInstance()[$name]) &&
            static::getResolvedInstance()[$name] instanceof Fake;
    }

    /**
     * Get the root object behind the facade.
     *
     * @return mixed
     */
    public static function getFacadeRoot()
    {
        return static::resolveFacadeInstance(static::getFacadeAccessor());
    }

    /**
     * Get the registered name of the component.
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    protected static function getFacadeAccessor()
    {
        throw new RuntimeException('Facade does not implement getFacadeAccessor method.');
    }

    /**
     * Resolve the facade root instance from the container.
     *
     * @param  string  $name
     * @return mixed
     */
    protected static function resolveFacadeInstance($name)
    {
        if (isset(static::getResolvedInstance()[$name])) {
            return static::getResolvedInstance()[$name];
        }

        if (static::getFacadeApplication()) {
            if (static::$cached) {
                $instance = static::getFacadeApplication()[$name];

                static::addResolvedInstance($name, $instance);

                return $instance;
            }

            return static::getFacadeApplication()[$name];
        }
    }

    public static function getResolvedInstance(): array
    {
        if (self::$facadeCacheResolver) {
            return (self::$facadeCacheResolver)();
        }

        return self::$resolvedInstance;
    }

    public static function addResolvedInstance(string $facadeAccessor, mixed $instance): void
    {
        if (self::$facadeCacheResolver) {
            $resolver = (self::$facadeCacheResolver)();

            $resolver[$facadeAccessor] = $instance;
            return;
        }

        self::$resolvedInstance[$facadeAccessor] = $instance;
    }

    /**
     * Clear a resolved facade instance.
     *
     * @param  string  $name
     * @return void
     */
    public static function clearResolvedInstance($name)
    {
        unset(static::getResolvedInstance()[$name]);
    }

    /**
     * Clear all of the resolved instances.
     *
     * @return void
     */
    public static function clearResolvedInstances()
    {
        static::$resolvedInstance = [];
    }

    /**
     * Get the application default aliases.
     *
     * @return \Illuminate\Support\Collection
     */
    public static function defaultAliases()
    {
        return collect([
            'App' => App::class,
            'Arr' => Arr::class,
            'Artisan' => Artisan::class,
            'Auth' => Auth::class,
            'Blade' => Blade::class,
            'Broadcast' => Broadcast::class,
            'Bus' => Bus::class,
            'Cache' => Cache::class,
            'Config' => Config::class,
            'Context' => Context::class,
            'Cookie' => Cookie::class,
            'Crypt' => Crypt::class,
            'Date' => Date::class,
            'DB' => DB::class,
            'Eloquent' => Model::class,
            'Event' => Event::class,
            'File' => File::class,
            'Gate' => Gate::class,
            'Hash' => Hash::class,
            'Http' => Http::class,
            'Js' => Js::class,
            'Lang' => Lang::class,
            'Log' => Log::class,
            'Mail' => Mail::class,
            'Notification' => Notification::class,
            'Number' => Number::class,
            'Password' => Password::class,
            'Process' => Process::class,
            'Queue' => Queue::class,
            'RateLimiter' => RateLimiter::class,
            'Redirect' => Redirect::class,
            'Request' => Request::class,
            'Response' => Response::class,
            'Route' => Route::class,
            'Schedule' => Schedule::class,
            'Schema' => Schema::class,
            'Session' => Session::class,
            'Storage' => Storage::class,
            'Str' => Str::class,
            'URL' => URL::class,
            'Validator' => Validator::class,
            'View' => View::class,
            'Vite' => Vite::class,
        ]);
    }

    /**
     * Get the application instance behind the facade.
     *
     * @return \Illuminate\Contracts\Foundation\Application|null
     */
    public static function getFacadeApplication()
    {
        return static::$appResolver ? (static::$appResolver)() : static::$app;
    }

    /**
     * Set the application instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application|null  $app
     * @return void
     */
    public static function setFacadeApplication($app)
    {
        static::$app = $app;
    }

    public static function setFacadeApplicationResolver(\Closure $resolver)
    {
        static::$appResolver = $resolver;
    }

    public static function setFacadeCacheResolver(\Closure $resolver)
    {
        static::$facadeCacheResolver = $resolver;
    }

    /**
     * Handle dynamic, static calls to the object.
     *
     * @param  string  $method
     * @param  array  $args
     * @return mixed
     *
     * @throws \RuntimeException
     */
    public static function __callStatic($method, $args)
    {
        $instance = static::getFacadeRoot();

        if (! $instance) {
            throw new RuntimeException('A facade root has not been set.');
        }

        return $instance->$method(...$args);
    }
}
