<?php

# Check we have been included and not called directly
require( dirname(__FILE__) . '/../../../functions/include-only.php' );

# check if site is demo
$User->is_demo();

# Don't corrupt output with php errors!
disable_php_errors();

/*
 * Discover new subnets with snmp
 *
 * Discover new slave subnets with snmp
 *
 *******************************/

# snmp class
$Snmp       = new phpipamSNMP ();

# scan disabled
if ($User->settings->enableSNMP!="1")           { $Result->show("danger", "SNMP module disabled", true, $ajax_loaded); }

# section check
if (!is_numeric($POST->sectionId))           { $Result->show("danger", "Invalid section Id", true, $ajax_loaded); }
if (!is_numeric($POST->subnetId))            { $Result->show("danger", "Invalid subnet Id", true, $ajax_loaded); }

$section = $Subnets->fetch_object("sections", "id", $POST->sectionId);
if ($section===false)                           { $Result->show("danger", "Invalid section Id", true, $ajax_loaded); }

# check section permissions
if($Subnets->check_permission ($User->user, $POST->sectionId) != 3) { $Result->show("danger", _('You do not have permissions to add new subnet in this section')."!", true, $ajax_loaded); }

# fetch devices that use get_routing_table query
$devices_used = $Tools->fetch_multiple_objects ("devices", "snmp_queries", "%get_routing_table%", "id", true, true);

# recalculate ids for info
foreach ($devices_used as $d) {
    $devices_info[$d->id] = $d;
}

// if none set die
if ($devices_used===false)                      { $Result->show("danger", "No devices for SNMP route table query available"."!", true, $ajax_loaded); }

$found = [];
// ok, we have devices, connect to each device and do query
foreach ($devices_used as $d) {
    // init
    $Snmp->set_snmp_device ($d);
    // execute
    try {
       $res = $Snmp->get_query("get_routing_table");
       // remove those not in subnet
       if (sizeof($res)>0) {
           // save for debug
           $debug[$d->hostname][$q] = $res;

           // save result
           $found[$d->id]["get_vlan_table"] = $res;
        }
    } catch (Exception $e) {
       // save for debug
       $debug[$d->hostname]["get_vlan_table"] = $res ?? null;

       $errors[] = $e->getMessage();
	}
}
# none and errors
if(sizeof($found)==0 && isset($errors))          { $Result->show("info", _("No new subnets found")."</div><hr><div class='alert alert-warning'>".implode("<hr>", $errors)."</div>", true, $ajax_loaded); }
# none
elseif(sizeof($found)==0) 	                     { $Result->show("info", _("No new subnets found")."!", true, $ajax_loaded); }
# ok
else {
    # fetch all permitted domains
    $permitted_domains = $Sections->fetch_section_domains ($POST->sectionId);
    # fetch all belonging vlans
    $cnt = 0;
    foreach($permitted_domains as $k=>$d) {
    	//fetch domain
    	$domain = $Tools->fetch_object("vlanDomains","id",$d);
    	// fetch vlans and append
    	$vlans = $Tools->fetch_multiple_objects("vlans", "domainId", $domain->id, "number");
    	//save to array
    	$out[$d]['domain'] = $domain;
    	$out[$d]['vlans']  = $vlans;
    	//count add
    	$cnt++;
    }
    //filter out empty
    $permitted_domains = array_filter($out);


    # fetch all permitted domains
    $permitted_nameservers = $Sections->fetch_section_nameserver_sets ($POST->sectionId);

    # fetch all belonging nameserver set
    $cnt = 0;

    # Only parse nameserver if any exists
    if($permitted_nameservers != false) {
    	foreach($permitted_nameservers as $k=>$n) {
    		// fetch nameserver sets and append
    		$nameserver_set = $Tools->fetch_multiple_objects("nameservers", "id", $n, "name", "namesrv1");
    		//save to array
    		$nsout[$n] = $nameserver_set;
    		//count add
    		$cnt++;
    	}
    	//filter out empty
    	$permitted_nameservers = isset($nsout) ? array_filter($nsout) : false;
    }

    $permitted_timeservers = $Sections->fetch_section_timeserver_sets ($POST->sectionId);

    # fetch all belonging timeserver set
    $cnt = 0;

    # Only parse timeserver if any exists
    if($permitted_timeservers != false) {
    	foreach($permitted_timeservers as $k=>$t) {
    		// fetch timeserver sets and append
    		$timeserver_set = $Tools->fetch_multiple_objects("timeservers", "id", $t, "name", "timesrv1");
    		//save to array
    		$tsout[$n] = $timeserver_set;
    		//count add
    		$cnt++;
    	}
    	//filter out empty
    	$permitted_timeservers = isset($tsout) ? array_filter($tsout) : false;
    }
    
    
    
    # fetch all IPv4 masks
    $masks =  $Subnets->get_ipv4_masks ();

    # fetch vrfs
    if($User->settings->enableVRF==1)
    $vrfs  = $Tools->fetch_all_objects("vrf", "name");
?>

<!-- header -->
<?php if ($ajax_loaded) { ?>
<div class="pHeader"><?php print _('Scan results'); ?></div>
<?php } ?>

<!-- content -->
<?php if ($ajax_loaded) { ?>
<div class="pContent">
<?php } ?>
        <?php

    	//table
        print '<form id="editSubnetDetailsSNMPall">';
        print "<input type='hidden' name='csrf_cookie' value='$csrf'>";
    	print "<table class='table table-striped table-top table-condensed' id='editSubnetDetailsSNMPallTable'>";

    	// titles
    	print "<tr>";
    	print "	<th>"._("Subnet")."</th>";
    	print "	<th>"._("Description")."</th>";
    	print "	<th>"._("VLAN")."</th>";
    	if($User->settings->enableVRF==1)
    	print "	<th>"._("VRF")."</th>";
    	print "	<th>"._("Nameservers")."</th>";
    	print "	<th>"._("Timeservers")."</th>";        
    	print "	<th style='width:5px;'></th>";
    	print "</tr>";

    	//set colspan
    	$colspan = $User->settings->enableVRF==1 ? 6 : 5;

    	// alive
    	$m=0;
    	foreach($found as $deviceid=>$device) {
        	// we need to check if subnetId != 0 and isFolder!=1 for overlapping
        	if($POST->subnetId!=="0") {
            	$subnet = $Tools->fetch_object("subnets", "id", $POST->subnetId);
            	if ($subnet===false)                { $Result->show("info", _("Invalid subnet ID")."!", true, true, false, true); }
        	}
        	// fetch device
        	$device_details = $Tools->fetch_object("devices", "id", $deviceid);

        	// loop
        	foreach ($device as $query_result ) {
            	if ($query_result!==false) {
                    //count results for each device
                	$dc=0;

                	print "<tr>";
                	print " <th colspan='$colspan' style='padding:10px;'> <i class='fa fa-times btn btn-xs btn-danger remove-snmp-results' data-target='device-$deviceid'></i> ".$device_details->hostname."</th>";
                	print "</tr>";

                    print "<tbody id=device-$deviceid>";
                	foreach ($query_result as $ip) {
                    	//get bitmask
                    	foreach ($masks as $k=>$n) {
                        	if ($n->netmask == $ip['mask']) {
                            	$ip['bitmask']=$k;
                            	break;
                        	}
                    	}

                    	$overlap = false;
                    	// check for overlapping
                    	if (isset($subnet)) {
                        	if ($subnet->isFolder!="1") {
                            	// check
                            	if ( $Subnets->is_subnet_inside_subnet ("$ip[subnet]/$ip[bitmask]", $Subnets->transform_address($subnet->subnet,"dotted")."/".$subnet->mask) === false ) {
                                	$overlap = true;
                            	}
                            	// same mask
                            	if ($ip['subnet']==$Subnets->transform_address($subnet->subnet,"dotted") && $ip['bitmask']==$subnet->mask) {
                                	$overlap = true;
                            	}
                        	}
                    	}

                    	// check overlapping
                        if ($overlap === false) {
                            $dc++;
                            print "<tr id='tr-$m'>";
                    		//ip
                    		print "<td>$ip[subnet]/$ip[bitmask]</td>";

                    		//section, description, hidden
                    		print "<td>";
                    		print " <input type='text' name='description-$m'>";
                    		print " <input type='hidden' name='subnet-$m' value='$ip[subnet]/$ip[bitmask]'>";
                    		print " <input type='hidden' name='subnet_dec-$m' value='".$Subnets->transform_address($ip['subnet'],"decimal")."'>";
                    		print " <input type='hidden' name='mask-$m' value='$ip[bitmask]'>";
                    		print " <input type='hidden' name='sectionId-$m' value='".escape_input($POST->sectionId)."'>";
                    		print " <input type='hidden' name='action-$m' value='add'>";
                    		print " <input type='hidden' name='device-$m' value='$deviceid'>";
                    		if(isset($POST->subnetId))
                    		print " <input type='hidden' name='masterSubnetId-$m' value='".escape_input($POST->subnetId)."'>";
                            else
                    		print " <input type='hidden' name='masterSubnetId-$m' value='0'>";
                    		print "</td>";

                    		//vlan
                            print "<td>";
                            print "<select name='vlanId-$m' class='form-control input-sm input-w-100'>";
                            print " <option disabled='disabled'>"._('Select VLAN')."</option>";
                            print " <option value='0'>". _('No VLAN')."</option>";
                        	# print all available domains
                        	foreach($permitted_domains as $d) {
                        		//more than default
                    			print "<optgroup label='".$d['domain']->name."'>";
                    			if($d['vlans'][0]!==null) {
                    				foreach($d['vlans'] as $v) {
                    					// set print
                    					$printVLAN = $v->number;
                    					if(!empty($v->name)) { $printVLAN .= " ($v->name)"; }
                                        print '<option value="'. $v->vlanId .'">'. $printVLAN .'</option>'. "\n";
                    				}
                    			}
                    			else {
                    				print "<option value='0' disabled>"._('No VLANs')."</option>";
                    			}
                    			print "</optgroup>";
                        	}
                            print "</select>";
                            print "</td>";

                            //vrf
                            print '	<td>' . "\n";
                            print '	<select name="vrfId-'.$m.'" class="form-control input-sm input-w-100">'. "\n";
                            //blank
                            print '<option disabled="disabled">'._('Select VRF').'</option>';
                            print '<option value="0">'._('None').'</option>';
                            if($vrfs!=false) {
                    	        foreach($vrfs as $vrf) {
                        	        // set permitted
                        	        $permitted_sections = pf_explode(";", $vrf->sections);
                        	        // section must be in array
                        	        if (is_blank($vrf->sections) || in_array($POST->sectionId, $permitted_sections)) {
                        				//cast
                        				$vrf = (array) $vrf;
                        				// set description if present
                        				$vrf['description'] = !is_blank($vrf['description']) ? " ($vrf[description])" : "";
                        	        	print '<option value="'. $vrf['vrfId'] .'">'.$vrf['name'].$vrf['description'].'</option>';
                        	        }
                    	        }
                            }
                            print ' </select>'. "\n";
                            print '	</td>' . "\n";

                            //nameserver
                            print "<td>";
                            print "<select name='nameserverId-$m' class='form-control input-sm input-w-100'>";
                            print "<optgroup label='"._('Select nameserver set')."'>";
                            print "<option value='0'>"._('No nameservers')."</option>";
                        	# print all available nameserver sets
                        	if ($permitted_nameservers!==false) {
                        		foreach($permitted_nameservers as $n) {

                        			if($n[0]!==null) {
                        				foreach($n as $ns) {
                        					// set print
                        					$printNS = "$ns->name";
                        					$printNS .= " (" . array_shift(pf_explode(";",$ns->namesrv1)).",...)";
                                            print '<option value="'. $ns->id .'">'. $printNS .'</option>'. "\n";
                        				}
                        			}
                        		}
                        	}
                            print "</optgroup>";
                            print "</select>";
                            print "</td>";

                            //timeserver
                            print "<td>";
                            print "<select name='timeserverId-$m' class='form-control input-sm input-w-100'>";
                            print "<optgroup label='"._('Select timeserver set')."'>";
                            print "<option value='0'>"._('No timeservers')."</option>";
                        	# print all available timeserver sets
                        	if ($permitted_timeservers!==false) {
                        		foreach($permitted_timeservers as $t) {

                        			if($t[0]!==null) {
                        				foreach($t as $ts) {
                        					// set print
                        					$printTS = "$ts->name";
                        					$printTS .= " (" . array_shift(pf_explode(";",$ts->timesrv1)).",...)";
                                            print '<option value="'. $ns->id .'">'. $printTS .'</option>'. "\n";
                        				}
                        			}
                        		}
                        	}
                            print "</optgroup>";
                            print "</select>";
                            print "</td>";                            
                            
                    		//remove button
                    		print 	"<td><a href='' class='btn btn-xs btn-danger remove-snmp-subnet' data-target-subnet='$m'><i class='fa fa-times'></i></a></td>";
                    		print "</tr>";

                    		$m++;
                		}
            		}
            		// none
            		if ($dc==0) {
                		print "<tr><td colspan='$colspan'>".$Result->show ("info", _("No subnets found"), false, false, true)."</td></tr>";
            		}
                	print "</tbody>";
        		}
    		}
    	}
    	print "</table>";
    	print "</form>";
    }

    // add button
    if($m>0) {
        print "<a class='btn btn-sm btn-success' id='add-subnets-to-section-snmp'><i class='fa fa-plus'></i> "._("Add subnets to section")."</a>";
    }

    // print errors
    if (isset($errors)) {
        print "<hr>";
        foreach ($errors as $e) {
            print $Result->show ("warning", $e, false, false, true);
        }
    }

    //print scan method
    print "<div class='text-right' style='margin-top:7px;'>";
    print " <span class='muted'>";
    print " Scan method: SNMP Route table<hr>";
    print " Scanned devices: <br>";
    foreach ($debug as $k=>$d) {
        print "&middot; ".$k."<br>";
    }
    print "</span>";
    print "</div>";

    # show debug?
    if($POST->debug==1) 				{ print "<pre>"; print_r($debug); print "</pre>"; }

    ?>

    <!-- result -->
    <div class="add-subnets-to-section-snmp-result"></div>

<?php if ($ajax_loaded) { ?>
</div>


<!-- footer -->
<div class="pFooter">
    <button class="btn btn-sm btn-default hidePopups"><?php print _('Cancel'); ?></button>
</div>
<?php }  ?>
