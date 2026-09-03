import { dbHelpers } from "./db.js";

function envBool(name, fallback = false) {
  const value = process.env[name];
  if (value == null) return fallback;
  return ["1", "true", "yes", "on"].includes(String(value).toLowerCase());
}

async function saveEc2Records(records) {
  for (const record of records) {
    await dbHelpers.run(
      `INSERT INTO ec2_patch_status (instance_id, instance_name, missing_count, installed_count, failed_count, reboot_required)
       VALUES (?, ?, ?, ?, ?, ?)`,
      [
        record.instanceId,
        record.instanceName,
        record.missingCount,
        record.installedCount,
        record.failedCount,
        record.rebootRequired ? 1 : 0
      ]
    );
  }
}

function mockEc2Records() {
  return [
    {
      instanceId: "i-0a1b2c3d4e5f001",
      instanceName: "wp-prod-1",
      missingCount: 4,
      installedCount: 112,
      failedCount: 0,
      rebootRequired: true
    },
    {
      instanceId: "i-0a1b2c3d4e5f002",
      instanceName: "api-prod-1",
      missingCount: 0,
      installedCount: 97,
      failedCount: 0,
      rebootRequired: false
    }
  ];
}

export async function syncEc2Status() {
  const region = process.env.AWS_REGION;
  const enableMock = envBool("ENABLE_MOCK_MONITORING", true);

  if (!region) {
    if (enableMock) {
      const records = mockEc2Records();
      await saveEc2Records(records);
      return { source: "mock", recordsInserted: records.length };
    }
    throw new Error("AWS_REGION is missing.");
  }

  try {
    const { SSMClient, DescribeInstancePatchStatesCommand } = await import("@aws-sdk/client-ssm");
    const { EC2Client, DescribeInstancesCommand } = await import("@aws-sdk/client-ec2");

    const ssmClient = new SSMClient({ region });
    const ec2Client = new EC2Client({ region });

    const ec2Data = await ec2Client.send(new DescribeInstancesCommand({}));
    const instanceIds = [];
    const nameMap = new Map();

    for (const reservation of ec2Data.Reservations || []) {
      for (const instance of reservation.Instances || []) {
        if (!instance.InstanceId) continue;
        instanceIds.push(instance.InstanceId);
        const nameTag = (instance.Tags || []).find((t) => t.Key === "Name");
        nameMap.set(instance.InstanceId, nameTag?.Value || instance.InstanceId);
      }
    }

    if (!instanceIds.length) {
      return { source: "aws", recordsInserted: 0 };
    }

    const patchData = await ssmClient.send(
      new DescribeInstancePatchStatesCommand({
        InstanceIds: instanceIds.slice(0, 50)
      })
    );

    const records = (patchData.InstancePatchStates || []).map((state) => ({
      instanceId: state.InstanceId || "unknown",
      instanceName: nameMap.get(state.InstanceId) || state.InstanceId || "unknown",
      missingCount: state.MissingCount || 0,
      installedCount: state.InstalledCount || 0,
      failedCount: state.FailedCount || 0,
      rebootRequired: Boolean(state.RebootOption === "RebootIfNeeded" && (state.MissingCount || 0) > 0)
    }));

    await saveEc2Records(records);

    return { source: "aws", recordsInserted: records.length };
  } catch (err) {
    if (enableMock) {
      const records = mockEc2Records();
      await saveEc2Records(records);
      return {
        source: "mock",
        recordsInserted: records.length,
        warning: `AWS fetch failed: ${err.message}`
      };
    }

    throw err;
  }
}
