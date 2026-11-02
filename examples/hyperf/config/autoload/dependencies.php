<?php
declare(strict_types=1);
return [
    Acme\HyperfDpop\Contract\TokenBindingResolverInterface::class => DpopExample\ExampleResolver::class,
    Acme\HyperfDpop\Contract\RefreshBindingResolverInterface::class => DpopExample\ExampleResolver::class,
];
