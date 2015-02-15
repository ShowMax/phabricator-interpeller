<?php

final class InterpellerGraphView extends AphrontView {

	private $header = '';
	private $tasks = array();
	private $viewer = NULL;
	private $handles = array();
	private $queries = NULL;
	private $uri = NULL;

	public function setURI ( $uri ) {
		$this->uri = '//' . $uri . '/ours-js/d3.v3.min.js';
	}

	public function setHeader ( $header ) {
		$this->header = $header;
	}

	public function setTasks ( $tasks ) {
		$this->tasks = $tasks;
	}

	public function setViewer ( $user ) {
		$this->viewer = $user;
	}

	public function setHandles ( array $handles ) {
		assert_instances_of( $handles, 'PhabricatorObjectHandle' );
		$this->handles = $handles;
		return $this;
	}

	public function setQueries ( PhabricatorSavedQuery $queries ) {
		$this->queries = $queries;
	}

	final public function render () {
		$filters = $this->queries->getParameter( 'filters', array() );

		$script_code = <<<EOT
<script type="text/javascript" charset="utf-8">
	var w = 1500, h = 900;

	var labelDistance = 0;

	var vis = d3.select( "div.is4u_graph" ).append( "svg:svg" ).attr( "width", w).attr( "height", h );

	var nodes = [];
	var labelAnchors = [];
	var labelAnchorLinks = [];
	var links = [];


EOT;
		$counter = 0;
		$tasks = array();

		Javelin::initBehavior('phabricator-hovercards');

		foreach ( $this->tasks as $task ) {
			$phid = $task->getPHID();
			$tasks[ $phid ][ "id" ] = $counter++;
			$tasks[ $phid ][ "is_source" ] = 0;
			$tasks[ $phid ][ "is_target" ] = 0;
		}

		foreach ( $this->tasks as $task ) {
			$phid = $task->getPHID();
			$target = $tasks[ $phid ][ "id" ];
			foreach ( $task->loadDependsOnTaskPHIDs() as $depend ) {
				if ( array_key_exists( $depend, $tasks ) ) {
					$source = $tasks[ $depend ][ "id" ];
					$tasks[ $phid ][ "is_target" ] = 1;
					$tasks[ $depend ][ "is_source" ] = 1;
					$script_code .= <<<EOT
		links.push( { source: $source, target: $target, weight: 0.2 } );

EOT;
				}
			}
		}

		$response = CelerityAPI::getStaticResourceResponse();

		foreach ( $this->tasks as $task ) {
			$phid = $task->getPHID();

			$title = trim( $task->getTitle() );
			$title = str_replace( "\n", ' ', $title );
			$owner = $this->handles[ $task->getOwnerPHID() ];

			# add progress and estimated hours to hovered title
			$field_list = PhabricatorCustomField::getObjectFields( $task, PhabricatorCustomField::ROLE_VIEW );
			$field_list->setViewer( $this->viewer )->readFieldsFromStorage( $task );
			foreach ( $field_list->getFields() as $key => $field ) {
				$field->setViewer( $this->viewer );
				$fname = $field->getFieldKey();
				$fvalue = $field->getProxy()->getFieldValue();
				$myFields[ $fname ] = $fvalue;
			}
			if ( !isset( $myFields[ 'std:maniphest:is4u:estimated-hours' ]) )
				$myFields[ 'std:maniphest:is4u:estimated-hours' ] = 1;
			if ( !isset( $myFields[ 'std:maniphest:is4u:progress' ]) )
				$myFields[ 'std:maniphest:is4u:progress' ] = 0;

			$title .= sprintf( " (%s, %d hours, %d %% progress)",
				 $owner->getName(), $myFields[ 'std:maniphest:is4u:estimated-hours' ], $myFields[ 'std:maniphest:is4u:progress' ] );
			$title = phutil_escape_html( $title );

			$myTask = $task->getOwnerPHID() == $this->viewer->getPHID();
			$blocker = $tasks[ $phid ][ "is_source" ] && ! $tasks[ $phid ][ "is_target" ];
			$myBlocker = $myTask && $blocker;
			$color = $myBlocker ? '#F00' : ( $blocker ? '#F3F' : ( $myTask ? '#00F' : '#777' ) );
			
			$id = $task->getId();
			$label = "T$id";
			$label = phutil_escape_html( $label );

			$meta_id = $response->addMetadata( array( 'hoverPHID' => $phid ) );

			$show = 1;
			$standalone = ! $tasks[ $phid ][ "is_source" ] && ! $tasks[ $phid ][ "is_target" ];
			if ( $standalone && $myTask && in_array( 'my_standalone', $filters ) )
				$show = 0;
			if ( $standalone && ! $myTask && in_array( 'others_standalone', $filters ) )
				$show = 0;

			$script_code .= <<<EOT
	var node = { label: "$label", title: "$title", color: "$color", meta: "$meta_id", show: "$show", my: "$myTask" };
	nodes.push( node );

EOT;
		}

		$script_code .= <<<EOT
	for ( var i = 0; i < links.length; i++ ) {
		links[ i ].source = nodes[ links[ i ].source ];  links[ i ].target = nodes[ links[ i ].target ];
	}

EOT;

		if ( in_array( 'others_clusters', $filters ) ) {
			$script_code .= <<<EOT
	for (var i = 0; i < nodes.length; i++ ) {
		nodes[ i ].keep = nodes[ i ].my == "1";
	}

	stop = false;
	while ( stop ) {
		stop = true;
		for ( var i = 0; i < links.length; i++ ) {
			if ( links[ i ].source.keep && ! links[ i ].target.keep ) {
				links[ i ].target.keep = true;
				stop = false;
			}
			if ( links[ i ].target.keep && ! links[ i ].source.keep ) {
				links[ i ].source.keep = true;
				stop = false;
			}
		}
	}

	for ( var i = 0; i < nodes.length; i++ )
		if ( ! nodes[ i ].keep )
			nodes[ i ].show = "0";

EOT;
		}

		if ( in_array( 'redundant_links', $filters ) ) {
			$script_code .= <<<EOT
	if ( ! Array.prototype.indexOf ) {
		Array.prototype.indexOf = function( elt /*, from*/ ) {
			var len = this.length >>> 0;

			var from = Number(arguments[1]) || 0;
			from = (from < 0) ? Math.ceil(from) : Math.floor(from);
			if (from < 0) from += len;

			for (; from < len; from++)
				if (from in this && this[from] === elt)
					return from;

			return -1;
		};
	}

/* In Phabricator all graphs are already acyclic, we can skip Tarjan algorithm
	// use Tarjan algorithm to divide the whole graph to strongly connected components
	var component = 0;
	var stack = [];
	var scc = [];
	for ( var i = 0; i < nodes.length; i++ ) {
		if ( nodes[ i ].tarjanIndex == null )
			strongconnect( nodes[ i ] );
	}

	function strongconnect ( v ) {
		v.tarjanIndex = component;
		v.tarjanLowlink = component++;
		stack.push( v );
		v.tarjanIsRemoved = false;
		for ( var j = 0; j < links.length; j++ ) {
			if ( links[ j ].source == v ) {
				if ( links[ j ].target.tarjanIndex == null ) {
					strongconnect( links[ j ].target );
					v.lowlink = min( v.tarjanLowlink, links[ j ].target.tarjanLowlink );
				} else if ( ! links[ j ].target.tarjanIsRemoved )
					v.lowlink = min( v.tarjanLowlink, links[ j ].target.tarjanIndex );
			}
		}
		if ( v.tarjanLowlink == v.tarjanIndex ) {
			var tarjanN = [];
			do {
				var w = stack.pop();
				w.tarjanIsRemoved = true;
				tarjanN.push( w );
			} while ( w != v );
			scc.push( tarjanN );
		}
	} */

	// transitive reduction if acyclic !!!
	for ( var i = 0; i < links.length; i++ )
		links[ i ].redundant = false;

	for ( var i = 0; i < nodes.length; i++ )
		for ( var j = 0; j < links.length; j++ ) {
			if ( links[ j ].source == nodes[ i ] ) {
				var connected = bfs( links[ j ].target );
				if ( connected.length > 0 )
					for ( var k = 0; k < links.length; k++ )
						if ( links[ k ].source == nodes[ i ] && connected.indexOf( links[ k ].target ) >= 0 )
							links[ k ].redundant = true;
			}
		}

	function bfs ( v ) {
		var queue = [];
		var result = [];
		queue.push( v );
		v.bfsMark = true;
		while ( queue.length > 0 ) {
			var t = queue.shift();
			if ( t != v ) result.push( t );
			for ( var i = 0; i < links.length; i++ ) {
				if ( links[ i ].source == t )
					if ( ! links[ i ].target.bfsMark ) {
						links[ i ].target.bfsMark = true;
						queue.push( links[ i ].target );
					}
			}
		}

		for ( var i = 0; i < nodes.length; i++ )
			nodes[ i ].bfsMark = false;

		return result;
	}

	for ( var i = links.length-1; i >= 0; i-- )
		if ( links[ i ].redundant ) 
			links.splice( i, 1 );
EOT;
	}

		$script_code .= <<<EOT

	for ( var i = 0; i < nodes.length; i++ ) {
		labelAnchors.push( { node: nodes[ i ] } );
		labelAnchors.push( { node: nodes[ i ] } );
	}

	for ( var i = 0; i < nodes.length; i++ ) { labelAnchorLinks.push( { source: i * 2, target: i * 2 + 1, weight: 1 } ); }

	var force = d3.layout.force()
		.size([w, h])
		.nodes(nodes)
		.links(links)
		.gravity(1)
		.linkDistance(50)
		.charge(-3000)
		.linkStrength(function(x) { return x.weight * 10 });
	force.start();

	var force2 = d3.layout.force()
		.nodes(labelAnchors)
		.links(labelAnchorLinks)
		.gravity(0)
		.linkDistance(0)
		.linkStrength(8)
		.charge(-100)
		.size([w, h]);
	force2.start();

	vis.append( "svg:defs" )
		.append( "marker" )
			.attr( "id", "triangle" )
			.attr( "viewbox", "0 0 10 10" )
			.attr( "markerWidth", 10 )
			.attr( "markerHeight", 10 )
			.attr( "refX", 15 )
			.attr( "refY", 5 )
			.attr( "orient", "auto" )
			.attr( "markerUnits", "strokeWidth" )
			.append( "polyline" )
				.attr( "points", "0,0 10,5 0,10 1,5" )
				.style( "fill", "#999" );

	var link = vis.selectAll("line.link")
		.data(links)
		.enter().append("svg:line")
			.attr("class", "link")
			.style("stroke", "#999")
			.attr( "marker-end", "url(#triangle)" );

	var new_links = force.links().filter( function ( d ) { return d.source.show == "1" && d.target.show == "1"; } )
	if ( new_links.length == 0 ) {
		force.links( [] );
		d3.selectAll("line.link").remove();
	} else
		force.links( new_links );
	force.nodes( force.nodes().filter( function ( d ) { return d.show == "1"; } ) );
	force2.nodes( force2.nodes().filter( function ( d ) { return d.node.show == "1"; } ) );

	var node = vis.selectAll("g.node")
		.data(force.nodes())
		.enter().append("svg:g")
			.attr("class", "node");
	node.append("svg:circle")
		.attr("r", 5)
		.style("fill", function (d) { return d.color; } )
		.style("stroke", "#FFF")
		.style("stroke-width", 3)
		.append( "title" )
			.text( function (d, i) { return d.label + " " + d.title; });
	node.call(force.drag);

	var anchorLink = vis.selectAll("line.anchorLink").data(labelAnchorLinks);

	var anchorNode = vis.selectAll("g.anchorNode")
		.data(force2.nodes())
		.enter().append("svg:g")
			.attr("class", "anchorNode");
	anchorNode.append("svg:circle")
		.attr("r", 0)
		.style("fill", "#FFF");
	anchorNode.append("svg:text")
		.attr( 'data-sigil', 'hovercard' )
		.attr( 'data-meta', function ( d ) { if ( d ) return d.node.meta; } )
		.text( function(d, i) { return i % 2 == 0 ? "" : d.node.label })
		.style( "fill", function ( d ) { return d.node.color; } )
		.style( "font-family", "Arial" )
		.style( "font-size", 12 )
		.style( "cursor", "pointer" )
		.on( 'click', function( d,i ) { if ( d ) { window.open( "/" + d.node.label, "_blank" ); d3.event.stopPropagation(); } } )
		.append( 'svg:title' )
			.text( function (d, i) { return i % 2 == 0 ? "" : d.node.label + " " + d.node.title });

	var updateLink = function() { 
		this
			.attr("x1", function(d) { return d.source.x; })
			.attr("y1", function(d) { return d.source.y; })
			.attr("x2", function(d) { return d.target.x; })
			.attr("y2", function(d) { return d.target.y; }); 
	}

	var updateNode = function() { this.attr("transform", function(d) { return "translate(" + d.x + "," + d.y + ")"; }); }

	force.on("tick", function() {
		force2.start();
		node.call(updateNode);

		anchorNode.each(function(d, i) {
			if(i % 2 == 0) {
				d.x = d.node.x;
				d.y = d.node.y;
			} else {
				var b = this.childNodes[1].getBBox();

				var diffX = d.x - d.node.x;
				var diffY = d.y - d.node.y;

				var dist = Math.sqrt(diffX * diffX + diffY * diffY);

				var shiftX = b.width * (diffX - dist) / (dist * 2);
				shiftX = Math.max(-b.width, Math.min(0, shiftX));
				var shiftY = 5;
				this.childNodes[1].setAttribute("transform", "translate(" + shiftX + "," + shiftY + ")");
			}
		});

		anchorNode.call(updateNode);
		link.call(updateLink);
		anchorLink.call(updateLink);
	});
</script>
EOT;

		$box_content = new PhutilSafeHTML( $script_code );

		$box = id( new PHUIBoxView() )
			->appendChild( phutil_tag( 'div', array( "class" => "is4u_graph" ), '' ) )
			->appendChild( phutil_tag( 'script', array( 'src' => $this->uri ) ) )
			->appendChild( $box_content )
			->appendChild( 
				phutil_tag( 'div', array(),
					pht( 'Links indicate dependency "this task has to be done before that". Blue one tasks are yours. Red ones represent your true blockers while magenta other blocking tasks.' ) )
			 )
			->addPadding( PHUI::PADDING_LARGE );
		
		$header = id( new PHUIHeaderView() )
			->setHeader( $this->header );
		
		$filter_header = id( new PHUIHeaderView() )
			->setHeader( pht( 'Filters' ) );

		$filter_defs = array( 
			'my_standalone' => pht( 'my standalone tasks' ),
			'others_standalone' => pht( 'others standalone tasks' ),
			'others_clusters' => pht( 'clusters without my tasks' ),
			'redundant_links' => pht( 'redundant dependencies' ),
/*			'stack_stucked' => 'tasks from Stucked stack',
			'stack_wishlist' => 'tasks from Wishlist stack',
			'stack_backlog' => 'tasks from Backlog stack',
			'stack_discussion' => 'tasks from Needs discussion stack',
			'stack_revision' => 'tasks from Needs revision stack',
			'wishlist_priority' => 'tasks with Wishlist priority' */
		);

		$cb = id( new AphrontFormCheckboxControl() )->setLabel( 'Hide' );
		foreach ( $filter_defs as $k => $v ) {
			$cb->addCheckbox( 'filters[]', $k, pht( $v ), in_array( $k, $filters ) );
		}
		$filter = id ( new AphrontFormView() )
			->setUser( $this->viewer )
			->appendChild( $cb )
			->appendChild( id( new AphrontFormSubmitControl() )->setValue( pht( 'Filter' ) ) );

		$filter_box = id( new PHUIBoxView() )
			->appendChild( $filter_header )
			->appendChild( $filter )
			->setBorder( true )
			->addMargin(PHUI::MARGIN_LARGE_TOP)
			->addMargin(PHUI::MARGIN_LARGE_LEFT)
			->addMargin(PHUI::MARGIN_LARGE_RIGHT)
			->addClass( 'phui-object-box' );

		$fullbox = id( new PHUIBoxView() )
			->appendChild( $header )
			->appendChild( $box )
			->setBorder( true )
			->addMargin(PHUI::MARGIN_LARGE_TOP)
			->addMargin(PHUI::MARGIN_LARGE_LEFT)
			->addMargin(PHUI::MARGIN_LARGE_RIGHT)
			->addClass( 'phui-object-box' );

		$rendered = id ( new PHUIBoxView() )
			->appendChild( $filter_box )
			->appendChild( $fullbox );

		return $rendered;
	}

}
