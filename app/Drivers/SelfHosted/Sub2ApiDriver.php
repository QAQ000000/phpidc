<?php

declare(strict_types=1);

namespace App\Drivers\SelfHosted;

/** Local-pool delivery reuses the pure card driver; remote API mode is not enabled. */
class Sub2ApiDriver extends RedemptionCodeDriver {}
