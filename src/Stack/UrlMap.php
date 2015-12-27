<?php

namespace Stack;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * URL Map Middleware, which maps kernels to paths
 *
 * Maps kernels to path prefixes and is insertable into a stack.
 *
 * @author Christoph Hochstrasser <christoph.hochstrasser@gmail.com>
 */
class UrlMap implements HttpKernelInterface, TerminableInterface
{
    const ATTR_PREFIX = "stack.url_map.prefix";

    protected $map = array();

    /**
     * @var HttpKernelInterface
     */
    protected $app;

    public function __construct(HttpKernelInterface $app, array $map = array())
    {
        $this->app = $app;

        if ($map) {
            $this->setMap($map);
        }
    }

    /**
     * Sets a map of prefixes to objects implementing HttpKernelInterface
     *
     * @param array $map
     */
    public function setMap(array $map)
    {
        # Collect an array of all key lengths
        $lengths = array_map('strlen', array_keys($map));

        # Sort paths by their length descending, so the most specific
        # paths go first. `array_multisort` sorts the lengths descending and
        # uses the order on the $map
        array_multisort($lengths, SORT_DESC, $map);

        $this->map = $map;
    }

    public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
    {
        $pathInfo = rawurldecode($request->getPathInfo());
        foreach ($this->map as $path => $app) {
            if (0 === strpos($pathInfo, $path)) {
                $server = $request->server->all();
                $server['SCRIPT_FILENAME'] = $server['SCRIPT_NAME'] = $server['PHP_SELF'] = $request->getBaseUrl().$path;

                $attributes = $request->attributes->all();
                $attributes[static::ATTR_PREFIX] = $request->getBaseUrl().$path;

                $newRequest = $request->duplicate(null, null, $attributes, null, null, $server);
                
                // Dirty fix for POTT #1265 or https://github.com/bolt/bolt/issues/2214
                // which is the issue that \Bolt\Conf:getWhichEnd uses
                // \Symfony\Component\HttpFoundation\Request:createFromGlobals() instead of relying on
                // the Request attributes.
                $newRequest->overrideGlobals();

                return $app->handle($newRequest, $type, $catch);
            }
        }

        return $this->app->handle($request, $type, $catch);
    }

    public function terminate(Request $request, Response $response)
    {
        foreach ($this->map as $path => $app) {
            if ($app instanceof TerminableInterface) {
                $app->terminate($request, $response);
            }
        }

        if ($this->app instanceof TerminableInterface) {
            $this->app->terminate($request, $response);
        }
    }
}
