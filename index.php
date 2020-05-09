<?php
require_once "includes.php";

$branches = get_branches( 'mediawiki/core' );

$branches = array_filter( $branches, function ( $branch ) {
	return preg_match( '/^origin\/(master|wmf|REL)/', $branch );
} );
natcasesort( $branches );

// Put newest branches first
$branches = array_reverse( array_values( $branches ) );

// Move master to the top
array_unshift( $branches, array_pop( $branches ) );

$branchesOptions = array_map( function ( $branch ) {
	return [ 'data' => $branch ];
}, $branches );

$repoBranches = [];
$repoOptions = [];
$repoData = get_repo_data();
ksort( $repoData );
foreach ( $repoData as $repo => $path ) {
	if ( $repo === 'mediawiki/core' ) {
		continue;
	}
	$repoBranches[$repo] = get_branches( $repo );
	$repo = htmlspecialchars( $repo );
	$repoOptions[] = [
		'data' => $repo,
		'label' => $repo,
	];
}
$repoBranches = htmlspecialchars( json_encode( $repoBranches ), ENT_NOQUOTES );
echo "<script>window.repoBranches = $repoBranches;</script>\n";

include_once 'DetailsFieldLayout.php';

echo new OOUI\FormLayout( [
	'infusable' => true,
	'method' => 'POST',
	'action' => 'new.php',
	'id' => 'new-form',
	'items' => [
		new OOUI\FieldsetLayout( [
			'label' => null,
			'items' => [
				new OOUI\FieldLayout(
					new OOUI\DropdownInputWidget( [
						'name' => 'branch',
						'options' => $branchesOptions,
					] ),
					[
						'label' => 'Start with version:',
						'align' => 'left',
					]
				),
				new OOUI\FieldLayout(
					new OOUI\MultilineTextInputWidget( [
						'name' => 'patches',
						'placeholder' => 'Gerrit changeset number or Change-Id, one per line',
						'rows' => 4,
					] ),
					[
						'label' => 'Then, apply patches:',
						'align' => 'left',
					]
				),
				new OOUI\FieldLayout(
					can_configure() ?
						new OOUI\MultilineTextInputWidget( [
							'name' => 'siteConfig',
							'placeholder' => "\$wgSitename = 'Test wiki';",
							'rows' => 4,
						] ) :
						new OOUI\MessageWidget( [
							'label' => 'Only trusted users can modify site config.',
						] ),
					[
						'label' => 'Site config:',
						'help' => new OOUI\HtmlSnippet( 'This file will be <strong>public</strong>.' ),
						'helpInline' => true,
						'align' => 'left',
					]
				),
				new DetailsFieldLayout(
					new OOUI\CheckboxMultiselectInputWidget( [
						'name' => 'repos[]',
						'options' => $repoOptions,
						'value' => array_keys( $repoData ),
					] ),
					[
						'label' => 'Choose extensions to enable:',
						'help' => new OOUI\HtmlSnippet( '<br/>Defaults to all' ),
						'helpInline' => true,
						'align' => 'left',
					]
				),
				new OOUI\FieldLayout(
					new OOUI\ButtonInputWidget( [
						'label' => 'Create demo',
						'type' => 'submit',
						// 'disabled' => true,
						'flags' => [ 'progressive', 'primary' ]
					] ),
					[
						'label' => ' ',
						'align' => 'left',
					]
				),
			]
		] ),
	]
] );
?>
<br/>
<h3>Previously generated wikis</h3>
<?php
if ( $user ) {
	echo new OOUI\FieldLayout(
		new OOUI\CheckboxInputWidget( [
			'classes' => [ 'myWikis' ]
		] ),
		[
			'align' => 'inline',
			'label' => 'Show only my wikis',
		]
	);
}
?>
<table class="wikis">
	<?php

	$dirs = array_filter( scandir( 'wikis' ), function ( $dir ) {
		return substr( $dir, 0, 1 ) !== '.';
	} );

	$usecache = false;
	$cache = get_if_file_exists( 'wikicache.json' );
	if ( $cache ) {
		$wikis = json_decode( $cache, true );
		$wikilist = array_keys( $wikis );
		sort( $wikilist );
		sort( $dirs );
		if ( $wikilist === $dirs ) {
			$usecache = true;
		}
	}

	if ( !$usecache ) {
		$wikis = [];
		foreach ( $dirs as $dir ) {
			if ( substr( $dir, 0, 1 ) !== '.' ) {
				$title = '?';
				$settings = get_if_file_exists( 'wikis/' . $dir . '/w/LocalSettings.php' );
				if ( $settings ) {
					preg_match( '`wgSitename = "(.*)";`', $settings, $matches );
					$title = $matches[ 1 ];

					preg_match( '`Patch Demo \((.*)\)`', $title, $matches );
					if ( count( $matches ) ) {
						preg_match_all( '`([0-9]+),([0-9]+)`', $matches[ 1 ], $matches );
						$title = implode( '<br>', array_map( function ( $r, $p, $t ) {
							$data = gerrit_get_commit_info( $r, $p );
							if ( $data ) {
								$t = $t . ': ' . $data[ 'subject' ];
							}
							return '<a href="https://gerrit.wikimedia.org/r/c/' . $r . '/' . $p . '" title="' . htmlspecialchars( $t, ENT_QUOTES ) . '">' .
								htmlspecialchars( $t ) .
							'</a>';
						}, $matches[ 1 ], $matches[ 2 ], $matches[ 0 ] ) );
					}

				}
				$creator = get_creator( $dir );
				$created = get_created( $dir );
				$siteConfig = get_if_file_exists( 'wikis/' . $dir . '/w/config.txt' );
				$hasConfig = $siteConfig && strlen( trim( $siteConfig ) );

				if ( !$created ) {
					// Add created.txt to old wikis
					$created = file_exists( 'wikis/' . $dir . '/w/LocalSettings.php' ) ?
						filemtime( 'wikis/' . $dir . '/w/LocalSettings.php' ) :
						filemtime( 'wikis/' . $dir );
					file_put_contents( 'wikis/' . $dir . '/created.txt', $created );
				}

				$wikis[ $dir ] = [
					'mtime' => $created,
					'title' => $title,
					'creator' => $creator,
					'hasConfig' => $hasConfig,
				];
			}
		}
		uksort( $wikis, function ( $a, $b ) use ( $wikis ) {
			return $wikis[ $a ][ 'mtime' ] < $wikis[ $b ][ 'mtime' ];
		} );

		file_put_contents( 'wikicache.json', json_encode( $wikis ) );
	}

	$rows = '';
	$anyCanDelete = false;
	foreach ( $wikis as $wiki => $data ) {
		$title = $data[ 'title' ];
		$creator = $data[ 'creator' ] ?? '';
		$username = $user ? $user->username : null;
		$canDelete = can_delete( $creator );
		$anyCanDelete = $anyCanDelete || $canDelete;
		$rows .= '<tr' . ( $creator !== $username ? ' class="other"' : '' ) . '>' .
			'<td class="title">' . ( $title ?: '<em>No patches</em>' ) . '</td>' .
			'<td>' .
				( !empty( $data[ 'hasConfig' ] ) ?
					'<a href="wikis/' . $wiki . '/w/config.txt">Config</a>' :
					''
				) .
			'</td>' .
			'<td><a href="wikis/' . $wiki . '/w">' . substr( $wiki, 0, 20 ) . '&hellip;</a></td>' .
			'<td class="date">' . date( 'c', $data[ 'mtime' ] ) . '</td>' .
			( $useOAuth ? '<td>' . ( $creator ? user_link( $creator ) : '?' ) . '</td>' : '' ) .
			( $canDelete ?
				'<td><a href="delete.php?wiki=' . $wiki . '">Delete</a></td>' :
				''
			) .
		'</tr>';
	}

	echo '<tr>' .
			'<th>Patches</th>' .
			'<th>Config</th>' .
			'<th>Link</th>' .
			'<th>Time</th>' .
			( $useOAuth ? '<th>Creator</th>' : '' ) .
			( $anyCanDelete ? '<th>Actions</th>' : '' ) .
		'</tr>' .
		$rows;

	?>
</table>
<script src="index.js"></script>
<?php
include "footer.html";
