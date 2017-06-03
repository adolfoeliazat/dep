<?php namespace H\Dep;

use Psr\Container\NotFoundExceptionInterface;

class NotFoundException extends \LogicException implements NotFoundExceptionInterface {

}