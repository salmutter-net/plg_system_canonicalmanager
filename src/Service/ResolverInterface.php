<?php

namespace SalmutterNet\Plugin\System\Canonicalmanager\Service;

interface ResolverInterface
{
    public function resolve(int $id, string $contextLanguage): ?array;
}
