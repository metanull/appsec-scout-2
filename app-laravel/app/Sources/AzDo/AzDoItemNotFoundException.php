<?php

namespace App\Sources\AzDo;

/** A file (or the branch it was read at) does not exist in an Azure DevOps Git repository. */
final class AzDoItemNotFoundException extends \InvalidArgumentException {}
