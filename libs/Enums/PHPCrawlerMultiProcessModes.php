<?php

namespace PHPCrawl\Enums;

/**
 * Multiprocessing-modes currently supported by phpcrawl.
 *
 * @package phpcrawl.enums
 */
class PHPCrawlerMultiProcessModes
{
    /**
     * Crawler runs in a single process
     *
     * @var int
     */
    public const MPMODE_NONE = 0;

    /**
     * Crawler runs in multiprocess-mode, usercode is executed by parent-process only.
     *
     * @var int
     */
    public const MPMODE_PARENT_EXECUTES_USERCODE = 1;

    /**
     * Crawler runs in multiprocess-mode, usercode is executed by child-processes directly.
     *
     * @var int
     */
    public const MPMODE_CHILDS_EXECUTES_USERCODE = 2;
}
