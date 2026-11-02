<?php
declare(strict_types=1);
return Hyperf\Context\ApplicationContext::setContainer(new Hyperf\Di\Container((new Hyperf\Di\Definition\DefinitionSourceFactory())()));
