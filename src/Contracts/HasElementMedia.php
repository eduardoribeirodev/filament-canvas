<?php

namespace EduardoRibeiroDev\FilamentCanvas\Contracts;

interface HasElementMedia
{
    /** Keyed collection declarations: label, accepted_mime_types, max_files, max_size (KB), reference_field; optional query, authorize, upload_owner, upload_collection and disk. */
    public function mediaCollections(): array;
}
