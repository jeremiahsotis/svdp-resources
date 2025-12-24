/**
 * @namespace WPGMZA
 * @module GoogleRouteRenderer
 * @requires WPGMZA.DirectionsRenderer
 */
jQuery(function($) {
	
	WPGMZA.GoogleRouteRenderer = function(map) {
		WPGMZA.DirectionsRenderer.apply(this, arguments);
		this.panel = $("#directions_panel_" + map.id);
	}
	
	WPGMZA.extend(WPGMZA.GoogleRouteRenderer, WPGMZA.DirectionsRenderer);
	
	WPGMZA.GoogleRouteRenderer.prototype.clear = function() {
		if (this.directionStartMarker) {
            this.map.removeMarker(this.directionStartMarker);
            delete this.directionStartMarker;
        }

        if (this.directionEndMarker) {
            this.map.removeMarker(this.directionEndMarker);
            delete this.directionEndMarker;
        }

		if(this.polyline) {
			this.map.removePolyline(this.polyline);
			delete this.polyline;
		}

        if(this.alternativePolylines){
            for(let alternativeRoute of this.alternativePolylines){
                this.map.removePolyline(alternativeRoute);
            }

            delete this.alternativePolylines;
        }

        if(this.stepMarker){
            this.stepMarker.infoWindow.close();
            this.map.removeMarker(this.stepMarker);
            delete this.stepMarker;
        }
		
		this.panel.html("");
	}
	
	WPGMZA.GoogleRouteRenderer.prototype.setDirections = function(directions) {
        this.clear();

        if(directions.routes && directions.routes.length){
            this.directions = directions;
            this.routes = directions.routes;

            this.selectRoute(0);
        } else {
            /* Nothing found */
        }
	}

    WPGMZA.GoogleRouteRenderer.prototype.selectRoute = function(routeId) {
        this.clear();
        if(this.routes && this.routes[routeId]){

            this.addRouteSelection(routeId);

            const route = this.routes[routeId];
            if(!route.polyline || !route.polyline.decoded){
                return;
            }

            /* Polyline */
            const path = [];
            for(let coord of route.polyline.decoded){
                const point = {
                    lat: coord.lat(),
                    lng: coord.lng()
                };
                
                path.push(point);
            }

            const settings = this.getPolylineOptions();
    
            this.polyline = WPGMZA.Polyline.createInstance({
                polydata: path,
                strokeWeight : `${settings.strokeWeight}`,
                strokeOpacity : `${settings.strokeOpacity}`,
                strokeColor : `${settings.strokeColor}`
            });
            
            /* Optionally, render alternative routes, visually, without actually making them part of the directions */
            this.renderAlternativeRoutes(routeId);

            this.map.addPolyline(this.polyline);


            /* Markers */
            if (this.directionStartMarker) {
                this.map.removeMarker(this.directionStartMarker);
            }
    
            if (this.directionEndMarker) {
                this.map.removeMarker(this.directionEndMarker);
            }
    
            this.directionStartMarker = WPGMZA.Marker.createInstance({
                position: path[0],
                icon: this.map.settings.directions_route_origin_icon,
                retina: this.map.settings.directions_origin_retina,
                disableInfoWindow: true
            });
    
            this.directionStartMarker._icon.retina = this.directionStartMarker.retina;
    
            this.map.addMarker(this.directionStartMarker);
    
            this.directionEndMarker = WPGMZA.Marker.createInstance({
                position: path[path.length - 1],
                icon: this.map.settings.directions_route_destination_icon,
                retina: this.map.settings.directions_destination_retina,
                disableInfoWindow: true
            });
    
            this.directionEndMarker._icon.retina = this.directionEndMarker.retina;
    
            this.map.addMarker(this.directionEndMarker);

            /* Add warnings */
            if(this.directions.travelMode && (this.directions.travelMode == "WALKING" || this.directions.travelMode == "BICYCLING")){
                if(!route.warnings){
                    route.warnings = [];
                }

                if(!route._hasWalkingWarning){
                    route.warnings.push("Walking and Cycling routes are in beta and may missing clear sidewalks, pedestrian paths, or bicycling paths.");
                    route._hasWalkingWarning = true;
                }   
            } else if(this.directions.travelMode && this.directions.travelMode == "TRANSIT"){
                if(this.directions.waypoints && this.directions.waypoints.length){
                    if(!route.warnings){
                        route.warnings = [];
                    }

                    if(!route._hasTransitWarning){
                        route.warnings.push("Transit directions do not support waypoints. Waypoints ignored.");
                        route._hasTransitWarning = true;
                    }
                }
            }

            if(route.warnings && route.warnings.length){
                this.addWarnings(route.warnings);
            }

            if(route.localizedValues){
                this.addTravelMetric(route.localizedValues.distance.text, route.localizedValues.staticDuration.text);
            }

            /* Stops - Origin */
            this.addStop(this.directions.origin);

            /* Steps */
            if(route.legs){
                let stepNo = 1;
                for(let legI in route.legs){
                    const leg = route.legs[legI];
                    if(leg.steps){
                        for(let step of leg.steps){
                            this.addStep(step, stepNo);
                            stepNo ++;
                        }
                    }

                    if(this.directions.waypoints && this.directions.waypoints[legI]){
                        /* Stops - Waypoint */
                        this.addStop(this.directions.waypoints[legI].location);
                    }
                }
            }

            /* Stops - Destination */
            this.addStop(this.directions.destination);

            if(this.map.settings.directions_fit_bounds_to_route){
                if(this.directionStartMarker && this.directionEndMarker){
                    this.fitBoundsToRoute(this.directionStartMarker.getPosition(), this.directionEndMarker.getPosition());
                }
            }
        }
    }

    WPGMZA.GoogleRouteRenderer.prototype.addRouteSelection = function(selected){
        if(this.routes && this.routes.length > 1){
            const div = $(`<div class='wpgmza-route-selection'></div>`);
            div.append(`<div class='wpgmza-route-selection-heading'>Routes:</div>`);

            for(let routeId in this.routes){
                let routeLabel = false;
                if(this.routes[routeId].routeLabels && this.routes[routeId].routeLabels.length){
                    routeLabel = this.routes[routeId].routeLabels[0];
                }

                if(routeLabel){
                    switch(routeLabel){
                        case 'SHORTER_DISTANCE':
                            routeLabel = "Shortest";
                            break;
                        case 'FUEL_EFFICIENT':
                            routeLabel = "Exco";
                            break;
                        case 'DEFAULT_ROUTE_ALTERNATE':
                            routeLabel = "Alternative";
                            break;
                        case 'ROUTE_LABEL_UNSPECIFIED':
                        case 'DEFAULT_ROUTE':
                        default:
                            routeLabel = "Best";
                            break;
                    }
                }

                let routeDescription = `Route ${(parseInt(routeId) + 1)}`;
                if(this.routes[routeId].description){
                    routeDescription = this.routes[routeId].description;
                }

                div.append(`<div class='wpgmza-route-selection-item ${selected == routeId ? 'current-route' : ''}' data-route-id='${routeId}'>
                                <div class='wpgmza-route-selection-description'><div>${routeDescription}</div><div class='wpgmza-route-selection-type'>${routeLabel}</div></div>
                                <div class='wpgmza-route-selection-metrics'>
                                    <div>${this.routes[routeId].localizedValues.distance.text}</div>
                                    <div>${this.routes[routeId].localizedValues.staticDuration.text}</div>
                                </div>
                            </div>`);
            }

            this.panel.append(div);

            div.find('.wpgmza-route-selection-item').on('click', (event) => {
                if(event.currentTarget){
                    const selectRouteId = $(event.currentTarget).data('route-id');
                    this.selectRoute(selectRouteId);
                }
            });
        }
    }

    WPGMZA.GoogleRouteRenderer.prototype.addStop = function(stop){
        const div = $(`<div class='wpgmza-directions-stop'>${stop}</div>`);
        this.panel.append(div);
    }

    WPGMZA.GoogleRouteRenderer.prototype.addWarnings = function(warnings){
        const div = $(`<div class='wpgmza-directions-route-warnings'></div>`);

        div.append("<div>Route Warnings</div>");
        for(let warning of warnings){
            div.append(`<div> - ${warning}</div>`);
        }

        this.panel.append(div);
    }

    WPGMZA.GoogleRouteRenderer.prototype.addTravelMetric = function(distance, time){
        const div = $(`<div class='wpgmza-directions-travel-time'><div>About ${time}</div><div>${distance}</div></div>`);
        this.panel.append(div);
    }

    WPGMZA.GoogleRouteRenderer.prototype.addStep = function(step, stepNo){
        const div = $("<div class='wpgmza-directions-step'></div>");
			
        div[0].wpgmzaDirectionsStep = step;
		
        let description = step.navigationInstruction && step.navigationInstruction.instructions ? step.navigationInstruction.instructions : '';
        let distance = step.localizedValues && step.localizedValues.distance ? step.localizedValues.distance.text : '';
        let duration = step.localizedValues && step.localizedValues.staticDuration ? step.localizedValues.staticDuration.text : '';

        
        if(description || distance || duration){
            let icon = "";
            if(step.navigationInstruction && step.navigationInstruction.maneuver){
                const iconRemap = step.navigationInstruction.maneuver.toLowerCase().replaceAll("_", "-");
                icon = `<div class='wpgmza-route-maneuver wpgmza-route-maneuver-${iconRemap}'></div>`;
            } else {
                icon = `<div class='wpgmza-route-maneuver wpgmza-route-maneuver-straight'></div>`;
            }

            if(!description){
                description = `Continue for ${distance}`;
                distance = "";
            }

            div.html(
                `<div class='wpgmza-route-instruction-inner' data-coordinate="${step.startLocation.latLng.latitude},${step.startLocation.latLng.longitude}">
                    <div class='wpgmza-route-instruction-icon'>${icon}</div>
                    <div class='wpgmza-route-instruction-description'><span class='wpgmza-route-instruction-step-no'>${stepNo}.</span>${description}</div>
                    <div class='wpgmza-route-instruction-metric'>
                        <span>${distance}</span>
                        <span>${duration}</span>
                    </div>
                </div>`
            );
		
            this.panel.append(div);

            div.on('click', (event) => {
                if(this.stepMarker){
                    this.map.removeMarker(this.stepMarker);
                }

                this.stepMarker = WPGMZA.Marker.createInstance({
                    position: new WPGMZA.LatLng({
                        lat: parseFloat(step.startLocation.latLng.latitude),
                        lng: parseFloat(step.startLocation.latLng.longitude)
                    }),
                });

                this.stepMarker.setOpacity(0);

                this.map.addMarker(this.stepMarker);

                this.stepMarker.setOffset(0, this.stepMarker._icon.dimensions.height);

                this.stepMarker.initInfoWindow();
                this.stepMarker.openInfoWindow();
                this.stepMarker.infoWindow.setContent(div.html());
                this.stepMarker.infoWindow.setPosition(this.stepMarker.getPosition());

                this.map.panTo(this.stepMarker.getPosition());
                this.map.setZoom(15);
            });
        }
        
    }
   
	
    WPGMZA.GoogleRouteRenderer.prototype.renderAlternativeRoutes = function(primaryId){
        if(WPGMZA.settings.disableDirectionsAltRoutePolylines){
            /* Manually opted out of the route alternatives */
            return;
        }

        if(this.routes && this.routes.length > 1){
            this.alternativePolylines = [];
            for(let routeId in this.routes){
                if(routeId != primaryId){
                    const route = this.routes[routeId];

                    /* Polyline */
                    const path = [];
                    for(let coord of route.polyline.decoded){
                        const point = {
                            lat: coord.lat(),
                            lng: coord.lng()
                        };
                        
                        path.push(point);
                    }

                    const settings = this.getPolylineOptions();
            
                    const alternative = WPGMZA.Polyline.createInstance({
                        polydata: path,
                        strokeWeight : `${settings.strokeWeight}`,
                        strokeOpacity : `${parseFloat(settings.strokeOpacity) / 2}`,
                        strokeColor : `${settings.strokeColor}`
                    });

                    alternative._routeId = routeId;

                    this.alternativePolylines.push(alternative);

                    alternative.on('click', () => {
                        this.selectRoute(alternative._routeId);
                    });
                    
                    this.map.addPolyline(alternative);
                }
            }
        }
    }
	
	
});