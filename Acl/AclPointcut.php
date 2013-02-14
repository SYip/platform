<?php

namespace Oro\Bundle\UserBundle\Acl;

use JMS\AopBundle\Aop\PointcutInterface;
use Doctrine\Common\Annotations\Reader;

use Oro\Bundle\UserBundle\Acl\Manager;

class AclPointcut implements PointcutInterface
{
    private $reader;

    public function __construct(Reader $reader)
    {
        $this->reader = $reader;
    }

    public function matchesClass(\ReflectionClass $class)
    {
        return true;
    }

    /**
     * Check method for Acl annotation
     *
     * @param  \ReflectionMethod $method
     * @return bool
     */
    public function matchesMethod(\ReflectionMethod $method)
    {
        /*if ($this->reader->getMethodAnnotation($method, Manager::ACL_ANNOTATION_CLASS)) {
            return true;
        }*/
        return false;

        return true;
    }
}
