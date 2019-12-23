<?php

$gwt->exceptor('Test Http Redirect status code 303: #1', function ($tester) {
    $response = new \DOF\HTTP\Test\DummyResponse(new \DOF\HTTP\Test\DummyKernel('cli-mock-server'));
    $response->redirect('foo.bar', 303);
}, DOF\HTTP\Exceptor\ResponseExceptor::class, 'INVALID_HTTP_REDIRECTION_CODE');

$gwt->exceptor('Test Http Redirect status code 303: #2', function ($tester) {
    $response = new \DOF\HTTP\Test\DummyResponse(new \DOF\HTTP\Test\DummyKernel('cli-mock-server'));
    $response->redirect('foo.bar', 303);
}, DOF\HTTP\Exceptor\ResponseExceptor::class, 'INVALID_HTTP_REDIRECTION_CODE');

$gwt->true('Test Http Redirect status code 301: #1', function ($tester) {
    $response = new \DOF\HTTP\Test\DummyResponse(new \DOF\HTTP\Test\DummyKernel('cli-mock-server'));
    $response->redirect('foo.bar', 301);

    return $response->isRedirection();
});

$gwt->true('Test Http Redirect status code 302: #1', function ($tester) {
    $response = new \DOF\HTTP\Test\DummyResponse(new \DOF\HTTP\Test\DummyKernel('cli-mock-server'));
    $response->redirect('foo.bar', 302);

    return $response->isRedirection();
});

$gwt->false('Test Http Redirect `cache-control` header #1', function ($tester) {
    $response = new \DOF\HTTP\Test\DummyResponse(new \DOF\HTTP\Test\DummyKernel('cli-mock-server'));
    $response->header('cache-control', 'max-age=86400');
    $response->redirect('foo.bar', 302);

    return $response->hasHeader('cache-control');
});

$gwt->false('Test Http Redirect `cache-control` header #2', function ($tester) {
    $response = new \DOF\HTTP\Test\DummyResponse(new \DOF\HTTP\Test\DummyKernel('cli-mock-server'));
    $response->header('cache-control', 'max-age=86400');
    $response->redirect('foo.bar', 301);

    return $response->hasHeader('cache-control');
});
