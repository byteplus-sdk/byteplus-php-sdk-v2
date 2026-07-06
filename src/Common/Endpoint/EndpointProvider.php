<?php
namespace Byteplus\Common\Endpoint;

abstract class EndpointProvider
{
    abstract public function endpointFor($service, $region, $customBootstrapRegion = null, $useDualStack = null);
}

class ResolvedEndpoint
{
    public $host;

    public function __construct($host)
    {
        $this->host = $host;
    }

    public function urlFor($schema = 'https')
    {
        return $schema . '://' . $this->host;
    }
}


?>
