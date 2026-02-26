/**
 * @namespace WPGMZA
 * @module GoogleRouteAPI
 * @requires WPGMZA.DirectionsService
 */
jQuery(function($) {
	
	WPGMZA.GoogleRouteAPI = function(map) {
		WPGMZA.DirectionsService.apply(this, arguments);
	}
	
	WPGMZA.extend(WPGMZA.GoogleRouteAPI, WPGMZA.DirectionsService);

    WPGMZA.GoogleRouteAPI.API_URL = "https://routes.googleapis.com/directions/v2:computeRoutes";
	
	WPGMZA.GoogleRouteAPI.prototype.route = function(request, callback) {
        const packet = this.getRequestData(request);

        const options = {
            url : WPGMZA.GoogleRouteAPI.API_URL,
            method : "POST",
            data: JSON.stringify(packet),
            contentType: "application/json; charset=utf-8",
            beforeSend : (xhr) => {
                xhr.setRequestHeader('X-Goog-Api-Key', WPGMZA.settings.googleMapsApiKey || "");
                xhr.setRequestHeader('X-Goog-FieldMask', "routes.duration,routes.localizedValues,routes.routeLabels,routes.distanceMeters,routes.polyline.encodedPolyline,routes.legs,routes.warnings,routes.description");
                xhr.setRequestHeader('Content-Type', "application/json");
            },
            success: (response, status, xhr) => {
				for(let key in request){
					response[key] = request[key];
                }

                response = this.filterResponseData(response);
				callback(response);
			}, 
            error: (error) => {
                if(error.status){
                    const response = {};
                    switch(error.status){
                        case 400: 
                            response.status = "INVALID_REQUEST";
                            break;
                        case 403:
                            response.status = "REQUEST_DENIED";
                            break;
                        case 404: 
                        default:
                            response.status = "ZERO_RESULTS";
                            break;
                    }

                    callback(response);
                }
            }
        };

		$.ajax(options);
	}

    WPGMZA.GoogleRouteAPI.prototype.getRequestData = function(request){
        let language = WPGMZA.locale.substr(0, 2);
        if(WPGMZA.locale == "he_IL"){
            language = "iw";
        }
        
        const travelModeRemaps = {
            DRIVING : "DRIVE",
            WALKING : "WALK",
            TRANSIT : "TRANSIT", 
            BICYCLING : "BICYCLE"
        }
        let travelMode = travelModeRemaps[request.travelMode.toUpperCase()] ? travelModeRemaps[request.travelMode.toUpperCase()] : travelModeRemaps.DRIVING;

        const unitSystem = request.unitSystem == WPGMZA.Distance.KILOMETERS ? "METRIC" : "IMPERIAL";

        const packed = {
            origin : {
                address : request.origin
            },
            destination : {
                address : request.destination
            },
            languageCode : language,
            travelMode : travelMode,
            units : unitSystem,
            computeAlternativeRoutes : true,
            routeModifiers : {
                avoidTolls : request.avoidTolls ? request.avoidTolls : false,
                avoidHighways : request.avoidHighways ? request.avoidHighways : false,
                avoidFerries : request.avoidFerries ? request.avoidFerries : false
            }
        };

        if(request.waypoints && request.waypoints.length){
            if(travelMode != "TRANSIT"){
                packed.intermediates = [];
                for(let waypoint of request.waypoints){
                    packed.intermediates.push({
                        address : waypoint.location
                    });
                }
            }
        }

        if(travelMode === travelModeRemaps.DRIVING){
            packed.routingPreference = 'TRAFFIC_AWARE';
        }

        return packed;
    }

    WPGMZA.GoogleRouteAPI.prototype.filterResponseData = function(response){
        if(response.routes){
            for(let i in response.routes){
                if(response.routes[i].polyline && response.routes[i].polyline.encodedPolyline){
                    response.routes[i].polyline.decoded = this.decodePolyline(response.routes[i].polyline.encodedPolyline);
                }
            }

            response.status = "OK";
        } else {
            if(response.error && response.error.code){
                switch(response.error.code){
                    case 400: 
                        response.status = "INVALID_REQUEST";
                        break;
                    case 403:
                        response.status = "REQUEST_DENIED";
                        break;
                    case 404: 
                    default:
                        response.status = "ZERO_RESULTS";
                        break;
                }
            } else {
                if(!response || !response.routes){
                    response.status = "ZERO_RESULTS";
                }
            }
        }
        
        return response;
    }

    WPGMZA.GoogleRouteAPI.prototype.decodePolyline = function(encoded){
        encoded = encoded.replace(/\\\\/g,"\\").replace(/\\"/g,'"');
        return google.maps.geometry.encoding.decodePath(encoded);
    }
	
});