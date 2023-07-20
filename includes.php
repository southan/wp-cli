<?php

foreach ( glob( __DIR__ . '/includes/*.php' ) as $file ) {
	require $file;
}
