<?php

namespace Byteplus\Common\Interceptor\Interceptors;

abstract class Interceptor
{
    abstract public function intercept(Context $context);
}

abstract class ResponseInterceptor
{
    abstract public function intercept(Context $context);
}

?>
