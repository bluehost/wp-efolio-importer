<?php
/*
Plugin Name: eFolio Importer
Description: Use this plugin to import your content from eFolio. Activate then <a href="tools.php?page=efolio-importer">Start Here</a>
Version: 0.8
Author: Mike Hansen
Author URI: http://mikehansen.me
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/



function efolio_remote_get( $url ) {
	//moved this to its own function in case I must resort back to curl
	$args = array(
				'method' 		=> 'GET',
				'timeout' 		=> 300, //wait it out
				'redirection' 	=> 20, //dont give up
				'user-agent' 	=> 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_8_2) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1309.0 Safari/537.17',
				'blocking' 		=> true,
				'compress' 		=> true,
				'decompress' 	=> true,
				'sslverify' 	=> false
				);
	$return = wp_remote_get( $url, $args );
	if( is_wp_error( $return ) ){
		echo "<div class='error'><p>Error on page : ".$url."</p></div>";
	}
	return $return;
}

function new_img( $src ) {
	
	$url = 'http://'.get_option( 'efolio_siteurl' );
	if( stristr( $src[1], 'http' ) ) {
		//this means it is an absolute link maybe to elsewhere
		return 'src="'.$src[1].'"';
	}
	$image = efolio_remote_get( $url.$src[1] );
	if( is_wp_error( $image ) ) {
		echo '<div class="error"><p>Something went wrong! When moving an image</p></div>';
		die( '<a href="tools.php?page=efolio-importer">Try Again</a>' );
	}
	$image = $image['body'];
	$fn = str_replace( "Uploads/", "", $src[1] );
	if( strlen( $fn ) > 15 ) {
		$fn = substr( $fn, -15 );
	}
	$new_img_loc = WP_CONTENT_DIR.'/uploads/'.date( 'Y/m' ).$fn;
	$new_img_url = WP_CONTENT_URL.'/uploads/'.date( 'Y/m' ).$fn;
	$fp = fopen( $new_img_loc, "w+" );
	fwrite( $fp, $image );
	fclose( $fp );
	return 'src="'.$new_img_url.'"'; //return new location
}

function remove_garbage( $j ) {
	$j = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $j);
	$j = preg_replace('/<link\b[^>]*>(.*?)\/>/is', "", $j);
	$j = preg_replace('/\s+/', ' ', $j);
	return $j;
}

function get_site_structure( $homepage ) {
	//find the nav and get the links.
	$nav_whole = explode( '<h2 class="hidden">Site Navigation</h2><ul class="nav flat">', $homepage );
	$nav_whole = explode( '</ul></div>', $nav_whole['1'] );
	$nav_whole['0']; //this is just the li from the nav.
	$nav_singles = explode( '<a href="', $nav_whole['0'] );
	$links = array( );
	for ( $i = 1; $i < count( $nav_singles ); $i++ ) {
		$link = explode( '"', $nav_singles[$i] );
		$links['link'][] = $link['0'];  
		$links['parent'][] = '0';   // this is zero because it has no parent
		}
	return $links;
}

function get_sub_pages( $parents, $url ) {
	$links = array( 'link' => array(), 'parent' => array() );
	$sub_links = array( 'link' => array(), 'parent' => array() );
	for( $i = 0; $i < count( $parents['link'] ); $i++ ) {
		$content = efolio_remote_get( $url.$parents['link'][$i] );
		if( is_wp_error( $content ) ) {
			echo '<div class="error"><p>Something went wrong! When grabbing sublinks.</p></div>';
			die( '<a href="tools.php?page=efolio-importer">Try Again</a>' );
		}
		//look at the sub menu and grab links.
		if( strlen( $content['body'] ) > 0) {
			$subnav = @explode( '<ul class="subnav">', $content['body'] );
			$subnav = @explode( '</ul></div>', $subnav[1] );
			$subnav = $subnav[0]; 
			if(strlen( $subnav) > 0 ) {
				$sub_singles = explode( '<a href="', $subnav );
				for( $s = 1; $s < count( $sub_singles ); $s++ ) {
					$sub_link = explode( '"', $sub_singles[$s] );
					$sub_links['link'][] = $sub_link[0]; 
					$sub_links['parent'][] = $parents['link'][$i];
				}
			}
		}
	}
	$links['link'] = array_merge( $parents['link'], $sub_links['link'] );
	$links['parent'] = array_merge( $parents['parent'], $sub_links['parent'] );
	//if there is more subpages than before go check again...
	if( count( $links ) > count( $parents ) ) {
		$links = get_sub_pages( $links, $url );
	}
	return $links;
}

function get_page_content( $structure, $url ) {
	//structure will always be an array.
	$pages = array( );
	for( $i =0; $i < count( $structure['link'] ); $i++ ){
		$p_info = efolio_remote_get( $url.$structure['link'][$i] );
		if( is_wp_error( $p_info ) ) {
		echo '<div class="error"><p>Something went wrong! When grabbing page info.</p></div>';
		die( '<a href="tools.php?page=efolio-importer">Try Again</a>' );
	}

		//$p_info['body'] = remove_garbage($p_info['body']);
		$pages['content'][$structure['link'][$i]] = $p_info['body'];
	}
	$pages['parent'] = $structure['parent'];
	return $pages;
}

function get_main_content( $pages, $slug, $content ) {
	$main = explode( '<a id="mainContentAnchor" name="mainContent"></a>', $content );
	$main = explode( '</div></div><div class="footerNav">', $main[1] );
	if( substr( $main[0], -6 ) == "</div>" )    {
		//strip off the last </div> if necessary
		$main[0] = substr( $main[0], 0,-6 );
	}
	/* this is where we need to look for images and move them to the media folder*/
	$main[0] = preg_replace_callback( '/src=["]([^"|\']+)"/i', 'new_img', $main[0]);

	return $main[0];//this is the main content...
}

function create_pages( $pages ) {

	//check that $pages has all the necesary keys
	if( !array_key_exists( 'content', $pages ) OR !array_key_exists( 'parent', $pages ) ) {
		return false;
	}

	$page_content = array();
	foreach( $pages['content'] as $k => $v ) {
		$page_content[$k] = get_main_content($pages['content'], $k, $v);
	}

	$pcount = 0;
	foreach ( $page_content as $slug => $content ) {
		$clean_slug = str_replace('/', "",$slug);
		$title = ucwords(str_replace('-', ' ', $clean_slug));
		$content = remove_garbage( $content );
		//echo $content."<hr/>";
		$post = array(
			'ID' =>  '',
			'menu_order' =>  '0', 
			'comment_status' => 'closed', 
			'ping_status' => 'open', 
			'pinged' => 'open', 
			'post_author' => '1', 
			'post_category' =>  '', 
			'post_content' => $content, 
			'post_date' => date('Y-m-d H:i:s') ,
			'post_date_gmt' =>  date('Y-m-d H:i:s') ,
			'post_excerpt' => '',
			'post_name' => $clean_slug ,
			'post_parent' => $pages['parent'][$pcount], 
			'post_password' =>  '',
			'post_status' => 'publish', 
			'post_title' => $title ,
			'post_type' => 'page', 
			'tags_input' => '' ,
			'to_ping' => '',
			'tax_input' =>  ''
			);  
		$id = wp_insert_post( $post );
		if( in_array( $slug, $pages['parent'] ) ) {
			//replace the slug with the id :)
			for( $i=0; $i<count( $pages['parent'] ); $i++ ) {
				if( $pages['parent'][$i] == $slug ) {
					$pages['parent'][$i] = $id;
				}
			}
			}
		$pcount++;
		}
	return true;
}

/*Start the WordPress Stuff */
function efolio_menu( ) {
	add_submenu_page(
		'tools.php',
		'Import Your eFolio Page',  // title of the page
		'eFolio Importer',   	 	// text to be used in options menu
		'manage_options',    	 	// permission/role that is the minimum for this
		'efolio-importer',   	 	// menu slug
		'efolio_options'          	// function callback
	);
}

function efolio_settings( ) {
	add_settings_section(
		'efolio_settings_group',     // id of the group
		'',   	     // title of the section
		'efolio_desc',          	 // obligatory callback function for rendering
		'efolio-importer'            // what page (slug) to add it to
		);
	register_setting(
		'efolio_settings_group',     // what group it belongs to
		'efolio_siteurl',            // data object name/id
		'efolio_siteurl_clean'	 			 // callback function for sanitizing
		);
	add_settings_field(
		'efolio_siteurl',            // id for this setting
		'Site URL:',               	 // label
		'efolio_siteurl_render',     // callback function for rendering
		'efolio-importer',           // page to add this setting to
		'efolio_settings_group'      // settings group to attach to 
		);
}

function efolio_siteurl_clean( $input ) {
	//remove http:// in case they pasted it from browser..
	$strip = array( 'http://', 'https://','/Home', '/' );
	$efolio_url = str_replace( $strip, '', $input );
	return urlencode( $efolio_url );
}

function efolio_desc( ) {
	if( !isset( $_GET['import-action'] ) OR $_GET['import-action'] != "true" ) {
	echo "<p><strong>Follow the instructions below to import your eFolio content.</strong></p>";
	?>
	<ol>
		<li>
		Log into your efolio account. (ex. http://username.efolio-site.com/Owner/)
		<br/>
		<img style="margin: 10px 0;" src="<?php echo plugins_url() . "/efolio-importer/img/login-page-small.png"; ?>"/>
		</li>
		<li>
		Click the "Designs" tab near the top right.
		<br/>
		<img style="margin: 10px 0;" src="<?php echo plugins_url() . "/efolio-importer/img/design-page-small.png"; ?>"/>
		</li>
		<li>
		Choose "Text Designs" from the dropdown on the left and select the design named "Simple".
		<br/>
		<img style="margin: 10px 0;" src="<?php echo plugins_url() . "/efolio-importer/img/simple-theme-small.png"; ?>"/>
		</li>
		<li>
		After completing the steps above. Please fill out the feild below and save.
		</li>   
	</ol>
	<?php
	}
}

function efolio_options() {
	?>
	<script type="text/javascript">
	jQuery(document).ready(function() {
		jQuery('#import_button').click(function() {
			jQuery('.spinner').show();
		})
	});
	</script>
	<div class="wrap">
	<?php screen_icon(); ?>
	<h2>eFolio Importer</h2>
	<?php
	if( !isset( $_GET['import-action'] ) OR $_GET['import-action'] != "true" ) {
		echo '<form action="options.php#import" method="post">';
		settings_fields( 'efolio_settings_group' );
		do_settings_sections( 'efolio-importer' );
		?>
		<p><em>After Saving your Site URL the import button will appear below.</em></p>
		<?php
		  submit_button( 'Save URL' );
		  echo '</form>';
	}
	if( strlen( get_option( 'efolio_siteurl' ) ) > 0 ) {
		/*****
		Code from the original function
		*****/
		if( isset( $_GET['import-action'] ) AND $_GET['import-action'] == "true" ) {
			$efolio_url = "http://" . get_option( 'efolio_siteurl' );
			$site = efolio_remote_get( $efolio_url );
			if( is_wp_error( $site ) ) {
				$site = efolio_remote_get( $efolio_url );
				if( is_wp_error( $site ) ) {
					die( "Failed to get content. Likely timed out." );
				}
			}
			$parents = get_site_structure( $site['body'] );
			$all_links = get_sub_pages( $parents, $efolio_url );
			$pages = get_page_content( $all_links, $efolio_url );
			if( create_pages( $pages ) ) {
				?>
				<div class="updated"><p>Your content has been imported. You can disable this plugin now.</p></div>
				<h4>A few suggestions</h4>
				<ul>
					<li>Setup a <a target="_blank" href="nav-menus.php">Custom Menu</a></li>
					<li><a target="_blank" href="options-reading.php">Set a page as your home page</a></li>
					<li><a href="plugins.php">Deactivate this plugin</a> <small>(you no longer need it)</small></li>
				</ul>
				<?php
			} else {
				?>
				<div class="error"><p>Could not create pages.</p></div>
				<?php
			}
		/*********
		end original code
		*********/
		} else {
			?>
			<p id="import"><em>Clicking the button below may take a few minutes to complete.</em></p>
			<div id="import_container" style="width:125px;">
			<a id="import_button" class="button button-primary" href="tools.php?page=efolio-importer&import-action=true">Import Now</a>
			<span class="spinner"></span>
			</div>
			<?php
		}
	}
	echo "</div>";
}

function efolio_siteurl_render() { ?>
	http://<input id="efolio_siteurl" type="text" value="<?php echo get_option( 'efolio_siteurl' ); ?>" name="efolio_siteurl">
	<p>
	<em>(ex. username.efolio-site.com)</em>
	</p>
<?php 
}

add_action( 'admin_menu', 'efolio_menu' );
add_action( 'admin_init', 'efolio_settings' ); 
//for some reason this breaks efolio inline styles.
remove_filter('the_content', 'wpautop');
?>
